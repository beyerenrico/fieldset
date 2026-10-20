<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\BackupRunLog;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\State\EnvFiles;
use Carbon\CarbonImmutable;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\RecordingDockerGateway;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Backups in `fsa status` and `fsa config` (§7, R32, R39, R57, plan U16, ADR 0084): the last backup, the schedule and its
 * next run, the safety backups with age and size, the alerts as warnings (from the run ledger, without a database), and
 * the backup keys of `fsa config set`, which apply, verify and revert like every key.
 */

beforeEach(function (): void {
    $this->config = new ConfigFixture;
    $this->instance = $this->config->instance;
    $this->instance->withRunningStack();
    $this->log = new BackupRunLog($this->instance->directory);
});

/** @param  array<string, mixed>  $run */
function ledgerRun(BackupRunLog $log, array $run): void
{
    $log->append(['kind' => 'scheduled', 'status' => 'succeeded', 'started_at' => '2026-10-19T03:30:00Z', 'finished_at' => '2026-10-19T03:30:09Z', 'backup' => 'scheduled-20261019T033000Z', 'bytes' => 4096, 'encrypted' => false, 'recorded' => true, ...$run]);
}

it('reports no backup yet, the schedule and its next run, and no warning for a fresh instance', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));

    [$code, $json] = $this->instance->run('fsa:status');
    $backup = $json['data']['backup'];

    expect($code)->toBe(0)
        ->and($json['data']['last_backup'])->toBeNull()
        ->and($backup['schedule'])->toBe(['time' => '03:30', 'time_zone' => 'UTC', 'retention' => 7, 'next_run' => '2026-10-20T03:30:00Z'])
        ->and($backup)->toMatchArray(['last' => null, 'encrypted' => false, 'alerts' => [], 'safety' => [], 'directory' => $this->instance->directory->root.'/backups'])
        ->and($backup['backups'])->toBe(['count' => 0, 'bytes' => 0, 'newest' => null])
        ->and(collect($json['warnings'])->pluck('code')->all())->not->toContain('backup_failed')->not->toContain('backup_overdue');
});

it('reports the last backup, the backups on disk and the safety backups with age and size', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
    ledgerRun($this->log, []);
    foreach (['scheduled-20261019T033000Z' => 4096, 'safety-upgrade-20261019T090000Z' => 2048] as $name => $bytes) {
        $dir = $this->instance->directory->ensureDirectory('backups/'.$name);
        file_put_contents($dir.'/database.dump', str_repeat('x', $bytes));
    }

    [, $json] = $this->instance->run('fsa:status');
    $backup = $json['data']['backup'];

    expect($json['data']['last_backup'])->toMatchArray(['kind' => 'scheduled', 'status' => 'succeeded', 'backup' => 'scheduled-20261019T033000Z', 'bytes' => 4096])
        ->and($backup['backups']['count'])->toBe(2)->and($backup['backups']['bytes'])->toBe(6144)
        ->and($backup['backups']['newest']['name'])->toBe('safety-upgrade-20261019T090000Z')
        ->and($backup['safety'])->toBe([['name' => 'safety-upgrade-20261019T090000Z', 'kind' => 'safety-upgrade', 'created_at' => '2026-10-19T09:00:00Z', 'bytes' => 2048, 'encrypted' => false, 'kept' => false, 'age_hours' => 3]])
        ->and($backup['schedule']['next_run'])->toBe('2026-10-20T03:30:00Z')
        ->and($backup['alerts'])->toBe([]);
});

it('warns about a failed backup, lock skips older than a day, and a slot missed by two hours', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
    ledgerRun($this->log, ['started_at' => '2026-10-17T03:30:00Z']);
    ledgerRun($this->log, ['status' => 'skipped', 'reason' => 'lock_held', 'backup' => null, 'started_at' => '2026-10-18T03:30:00Z']);
    ledgerRun($this->log, ['status' => 'failed', 'reason' => 'pg_dump_failed', 'message' => 'connection refused', 'backup' => null, 'started_at' => '2026-10-19T03:30:00Z']);

    [$code, $json] = $this->instance->run('fsa:status');
    $warnings = collect($json['warnings'])->keyBy('code');

    expect($code)->toBe(0)
        ->and($json['data']['backup']['alerts'])->toBe(['backup_failed', 'backup_skipped', 'backup_overdue'])
        ->and($warnings['backup_failed']['message'])->toContain('pg_dump_failed')->toContain('connection refused')
        ->and($warnings['backup_failed']['hint'])->toContain('fsa backup')
        ->and($warnings['backup_skipped']['message'])->toContain('24 hours')
        ->and($warnings['backup_overdue']['message'])->toContain('2 hours of the scheduled slot')
        ->and($json['data']['last_backup']['started_at'])->toBe('2026-10-17T03:30:00Z');
});

it('warns at once about a run skipped for lack of disk space, in its own words', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19T04:00:00Z'));
    ledgerRun($this->log, ['started_at' => '2026-10-18T03:30:00Z']);
    ledgerRun($this->log, ['status' => 'skipped', 'reason' => 'low_disk_space', 'message' => 'Not enough free space: 10 MiB free, the backup would leave less than 1024 MiB.', 'backup' => null, 'started_at' => '2026-10-19T03:30:00Z']);

    [, $json] = $this->instance->run('fsa:status');
    $warnings = collect($json['warnings'])->keyBy('code');

    expect($json['data']['backup']['alerts'])->toBe(['backup_disk_space'])
        ->and($warnings['backup_disk_space']['message'])->toContain('Not enough free space')->not->toContain('lock')
        ->and($warnings['backup_disk_space']['hint'])->toContain('Free space');
});

it('warns about a backup service that cannot start and names a command that renders its configuration again (gate review DOC16)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
    ledgerRun($this->log, ['started_at' => '2026-10-19T03:30:00Z']);
    $this->log->append(['kind' => 'service', 'status' => 'failed', 'reason' => 'invalid_backup_config', 'message' => 'FSA_BACKUP_RETENTION must be a whole number.', 'started_at' => '2026-10-19T10:00:00Z', 'finished_at' => '2026-10-19T10:00:00Z', 'encrypted' => false]);

    [, $json] = $this->instance->run('fsa:status');
    $warnings = collect($json['warnings'])->keyBy('code');

    expect($json['data']['backup']['alerts'])->toContain('backup_service_failed')
        ->and($warnings['backup_service_failed']['message'])->toContain('invalid_backup_config')
        ->and($warnings['backup_service_failed']['hint'])->toContain('fsa logs backup')->toContain('fsa config set')->not->toContain('fsa install');
});

it('warns about a .partial directory an interrupted backup left, but not while an operation holds the lock', function (): void {
    $this->instance->directory->ensureDirectory('backups/manual-20261019T120000Z.partial');

    [, $json] = $this->instance->run('fsa:status');
    $warnings = collect($json['warnings'])->keyBy('code');
    $handle = new InstanceLock($this->instance->directory, new RecordingDockerGateway)->acquire(TemporaryInstance::holder('c'), 'backup');
    [, $locked] = $this->instance->run('fsa:status');
    new InstanceLock($this->instance->directory, new RecordingDockerGateway)->release($handle);

    expect($json['data']['backup']['partials'])->toBe(['manual-20261019T120000Z.partial'])
        ->and($warnings['backup_partial_left']['message'])->toContain('manual-20261019T120000Z.partial')->toContain('unencrypted')
        ->and($locked['data']['backup']['partials'])->toBe([])
        ->and(collect($locked['warnings'])->pluck('code')->all())->not->toContain('backup_partial_left');
});

it('warns when the newest successful backup misses referenced files (M12 gate D5)', function (): void {
    new BackupRunLog($this->instance->directory)->append(['kind' => 'scheduled', 'status' => 'succeeded', 'started_at' => '2026-10-19T03:30:00Z', 'backup' => 'scheduled-20261019T033000Z', 'missing_files' => 3]);

    [, $json] = $this->instance->run('fsa:status');
    $warning = collect($json['warnings'])->firstWhere('code', 'backup_files_missing');

    expect($warning['message'] ?? '')->toContain('3 file(s)')->toContain('scheduled-20261019T033000Z')
        ->and($json['data']['backup']['alerts'])->toContain('backup_files_missing');
});

it('also warns about the plain set of an encrypted backup an interrupted run left in staging/backup/', function (): void {
    $this->instance->directory->ensureDirectory('staging/backup/manual-20261019T120000Z.partial');

    [, $json] = $this->instance->run('fsa:status');
    $warnings = collect($json['warnings'])->keyBy('code');

    expect($json['data']['backup']['partials'])->toBe(['staging/backup/manual-20261019T120000Z.partial'])
        ->and($warnings['backup_partial_left']['message'])->toContain('staging/backup/manual-20261019T120000Z.partial')->toContain('unencrypted');
});

it('counts "overdue" without a success from the backup service\'s first start with the schedule, not from the install', function (): void {
    $installed = CarbonImmutable::parse((string) collect($this->instance->state()->history())->firstWhere('event', 'installed')['at']);
    $now = $installed->addDays(3);
    $this->travelTo($now);

    [, $before] = $this->instance->run('fsa:status');
    ledgerRun($this->log, ['kind' => 'service', 'status' => 'started', 'started_at' => $now->subHour()->toIso8601ZuluString(), 'schedule' => '03:30', 'backup' => null]);
    [, $started] = $this->instance->run('fsa:status');
    $this->travelTo($now->addDay()->addHours(3));
    [, $missed] = $this->instance->run('fsa:status');

    expect($before['data']['backup']['alerts'])->toBe(['backup_overdue']) // the service never reported: from the install
        ->and($started['data']['backup']['alerts'])->toBe([]) // an upgraded instance gets its first slot first
        ->and($started['data']['backup']['last'])->toBeNull() // the start record is not a backup run
        ->and($missed['data']['backup']['alerts'])->toBe(['backup_overdue']);
});

it('shows the backup settings and keys in fsa config show', function (): void {
    [$code, $json] = $this->config->show();
    $keys = collect($json['data']['keys'])->keyBy('key');

    expect($code)->toBe(0)
        ->and($json['data']['settings']['backup'])->toBe(['schedule' => '03:30', 'time_zone' => 'UTC', 'retention' => 7, 'encryption' => ['enabled' => false, 'recipients' => []], 'directory' => $this->instance->directory->root.'/backups'])
        ->and($keys['backup-schedule'])->toMatchArray(['value' => '03:30', 'settable_now' => true, 'changes_url' => false])
        ->and($keys['backup-retention'])->toMatchArray(['value' => '7', 'settable_now' => true])
        ->and($keys['backup-encryption'])->toMatchArray(['value' => 'off', 'settable_now' => true])
        ->and($json['data']['secrets'])->toHaveKeys(['backup_database_password', 'backup_hmac_key']);
});

it('changes the schedule and records the host time zone, recreating only the backup service', function (): void {
    $this->instance->facts(['timezone' => 'Europe/Berlin']);

    [$code, $json] = $this->config->set('backup-schedule', '04:15');
    $env = new EnvFiles($this->instance->directory)->read('backup');
    [$sameCode, $same] = $this->config->set('backup-schedule', '04:15');
    [$offCode] = $this->config->set('backup-schedule', 'off');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toMatchArray(['key' => 'backup-schedule', 'from' => '03:30', 'to' => '04:15'])
        ->and($json['data']['services'])->toBe(['recreated' => ['backup'], 'added' => [], 'removed' => []])
        ->and($env)->toMatchArray(['BACKUP_SCHEDULE' => '04:15', 'BACKUP_TIME_ZONE' => 'Europe/Berlin'])
        ->and($sameCode)->toBe(0)->and($same['changed'])->toBeFalse()
        ->and($offCode)->toBe(0)->and($this->config->state()->setting('backup_schedule'))->toBe('off')
        ->and($this->config->state()->setting('time_zone'))->toBe('Europe/Berlin');
});

it('changes the retention and refuses values outside 1 to 365 or a malformed time', function (): void {
    [$code, $json] = $this->config->set('backup-retention', '14');
    [$defaultCode, $default] = $this->config->set('backup-retention', '14');
    $refused = array_map(fn (array $set): array => $this->config->set(...$set), [['backup-retention', '0'], ['backup-retention', '366'], ['backup-retention', 'seven'], ['backup-schedule', '3:30'], ['backup-schedule', '25:00']]);

    expect($code)->toBe(0)->and($json['data']['to'])->toBe('14')
        ->and(new EnvFiles($this->instance->directory)->read('backup')['BACKUP_RETENTION'])->toBe('14')
        ->and($defaultCode)->toBe(0)->and($default['changed'])->toBeFalse()
        ->and(array_map(static fn (array $result): array => [$result[0], $result[1]['errors'][0]['code']], $refused))->toBe(array_fill(0, 5, [2, 'invalid_value']));
});

it('records the host time zone at install and sets up the backup role after the migrations', function (): void {
    $fixture = new InstanceCommandFixture;
    $fixture->facts(['timezone' => 'America/New_York']);

    [$code] = $fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    $calls = array_map(static fn (array $call): string => $call['command'][2] ?? '', $fixture->docker->called('composeRun'));

    expect($code)->toBe(0)
        ->and($fixture->state()->setting('time_zone'))->toBe('America/New_York')
        ->and(array_search('instance:backup-role', $calls, true))->toBe(array_search('migrate', $calls, true) + 1)
        ->and(is_dir($fixture->directory->root.'/backups'))->toBeTrue()
        ->and((string) $fixture->directory->read('staging/backup/instance.json'))->toContain('"mode": "local"');
});
