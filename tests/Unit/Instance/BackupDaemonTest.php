<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\AgeCli;
use App\Domain\Instance\Backup\BackupConfig;
use App\Domain\Instance\Backup\BackupDaemon;
use App\Domain\Instance\Backup\BackupRun;
use App\Domain\Instance\Backup\BackupRunLog;
use App\Domain\Instance\Backup\BackupRunRecorder;
use App\Domain\Instance\Backup\BackupSchedule;
use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\Backup\BackupTools;
use App\Domain\Instance\Backup\BackupWriter;
use App\Domain\Instance\Backup\ServiceIdentity;
use App\Domain\Instance\Console\BackupDaemonCommand;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockHolder;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Platform\Instance\BackupAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Instance\RecordingDockerGateway;
use Tests\Support\Instance\TemporaryInstance;

/*
 * The backup service's daemon and entry point (KTD4, KTD15, plan U16, ADR 0084), without a database: the schedule, the
 * lock (a run skipped while an operation holds it is recorded once), the free-space rule, the one-off's lock check and
 * its JSON document, and the lock holder of a service that has no Docker socket. The writing itself is
 * tests/Feature/Instance/BackupWriterTest.php.
 */

const DAEMON_KEY = 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

function daemonConfig(InstanceDirectory $directory, array $overrides = []): BackupConfig
{
    return new BackupConfig(...[
        'instanceRoot' => $directory->root, 'storagePath' => $directory->root, 'ownerUid' => posix_getuid(), 'ownerGid' => posix_getgid(),
        'schedule' => '03:30', 'timeZone' => 'UTC', 'retention' => 7, 'minFreeMib' => 0, 'recipients' => [], 'hmacKey' => str_repeat('k', 32),
        'fingerprints' => ['0123-4567-89ab-cdef'], 'database' => ['host' => 'postgres', 'port' => '5432', 'database' => 'x', 'username' => 'fsa_backup', 'password' => 'p'],
        'appVersion' => '2.0.0-test', ...$overrides,
    ]);
}

/** A run whose database is unreachable on purpose: these tests never get as far as a dump. */
function daemonRun(InstanceDirectory $directory, BackupConfig $config): BackupRun
{
    config(['database.connections.backup_none' => [...config('database.connections.pgsql'), 'database' => 'fsa_test_no_such_database']]);
    $db = DB::connection('backup_none');
    $store = new BackupStore($directory);

    return new BackupRun(new InstanceLock($directory, new RecordingDockerGateway), $store, new BackupWriter($store, $db, new BackupTools, new AgeCli, $config), new BackupRunRecorder(new BackupRunLog($directory, $store), $db), $config);
}

beforeEach(function (): void {
    $this->directory = TemporaryInstance::directory();
    $this->holder = new LockHolder(ServiceIdentity::OWNER, str_repeat('b', 64), 'proc:4242', 1, 'backup', null);
    $this->sleeps = [];
    $this->travelTo(CarbonImmutable::parse('2026-10-19T03:29:50Z')); // the daemon and its runs share the test clock
});

afterEach(fn () => TemporaryInstance::cleanup());

function daemon(object $test, BackupConfig $config): BackupDaemon
{
    return new BackupDaemon(
        $config->schedule(), new BackupRunLog($test->directory), daemonRun($test->directory, $config), new InstanceLock($test->directory, new RecordingDockerGateway), $test->holder,
        function (int $seconds) use ($test): void {
            $test->sleeps[] = $seconds;
            $test->travel($seconds)->seconds();
        },
        static fn (): CarbonImmutable => CarbonImmutable::now('UTC'),
    );
}

it('sleeps in short steps until the slot, then runs; a run skipped because the lock is held is recorded only once', function (): void {
    $other = TemporaryInstance::holder('c', '2026-10-19T03:00:00Z');
    $lock = new InstanceLock($this->directory, new RecordingDockerGateway);
    $handle = $lock->acquire($other, 'upgrade');
    $daemon = daemon($this, daemonConfig($this->directory));
    $since = CarbonImmutable::parse('2026-10-18T12:00:00Z');

    $first = $daemon->tick(CarbonImmutable::parse('2026-10-19T03:00:00Z')); // a fresh daemon: waits for the slot
    $runs = [];
    for ($i = 0; $i < 12 && count($runs) < 3; $i++) {
        $run = $daemon->tick($since);
        if ($run !== null) {
            $runs[] = $run;
        }
    }
    $ledger = new BackupRunLog($this->directory)->recent();

    expect($first)->toBeNull()->and($this->sleeps[0])->toBe(10)
        ->and(array_column($runs, 'status'))->toBe(['skipped', 'skipped', 'skipped'])
        ->and($runs[0])->toMatchArray(['kind' => 'scheduled', 'reason' => 'lock_held', 'recorded' => false])
        ->and($runs[0]['message'])->toContain('upgrade by fsa')
        ->and($ledger)->toHaveCount(1)
        ->and(max($this->sleeps))->toBeLessThanOrEqual(BackupDaemon::TICK_SECONDS)
        ->and(CarbonImmutable::parse((string) $runs[1]['started_at'])->diffInSeconds(CarbonImmutable::parse((string) $runs[2]['started_at'])))->toBeGreaterThanOrEqual(BackupSchedule::LOCK_RETRY_SECONDS)
        ->and($lock->current()?->lockId)->toBe($handle->record->lockId);
});

it('records a skip because of the lock again after an hour, so a lock left behind keeps showing up', function (): void {
    new InstanceLock($this->directory, new RecordingDockerGateway)->acquire(TemporaryInstance::holder('c', '2026-10-19T03:00:00Z'), 'upgrade');
    $daemon = daemon($this, daemonConfig($this->directory));
    $since = CarbonImmutable::parse('2026-10-17T12:00:00Z'); // the slot of 2026-10-18 03:30 was missed: due at once

    $first = $daemon->tick($since);
    $this->travel(30)->minutes();
    $within = $daemon->tick($since);
    $this->travel(31)->minutes();
    $later = $daemon->tick($since);

    expect([$first['recorded'] ?? null, $within['recorded'] ?? null, $later['recorded'] ?? null])->toBe([false, false, false]) // no database here: false means "not in the table"
        ->and(array_column(new BackupRunLog($this->directory)->recent(), 'reason'))->toBe(['lock_held', 'lock_held']);
});

it('never runs back to back when its ledger cannot be written', function (): void {
    mkdir($this->directory->root.'/backups/runs.log', 0700, true); // a directory where the ledger file belongs
    $daemon = daemon($this, daemonConfig($this->directory, ['minFreeMib' => 999_999_999]));
    $since = CarbonImmutable::parse('2026-10-17T12:00:00Z');

    $run = $daemon->tick($since);
    $again = $daemon->tick($since);

    expect($run)->toMatchArray(['status' => 'skipped', 'reason' => 'low_disk_space'])
        ->and($again)->toBeNull()->and($this->sleeps)->toBe([BackupDaemon::TICK_SECONDS]);
});

it('skips a damaged ledger line instead of failing', function (): void {
    $this->directory->ensureDirectory('backups');
    file_put_contents($this->directory->root.'/backups/runs.log', implode("\n", [
        '{"kind":"scheduled","status":"succeeded","started_at":"2026-10-18T03:30:00Z"}',
        '{"kind":"scheduled","status":"failed","started_at":"not a time"}',
        '{"kind":"sched',
    ])."\n");
    $log = new BackupRunLog($this->directory);

    expect($log->recent())->toHaveCount(1)->and($log->lastSuccess()?->toIso8601ZuluString())->toBe('2026-10-18T03:30:00Z')
        ->and($log->lastFailedAttempt())->toBeNull();
});

it('skips a scheduled run when the disk is too full, and records it', function (): void {
    $daemon = daemon($this, daemonConfig($this->directory, ['minFreeMib' => 999_999_999]));

    $run = $daemon->tick(CarbonImmutable::parse('2026-10-18T00:00:00Z'));

    expect($run)->toMatchArray(['status' => 'skipped', 'reason' => 'low_disk_space'])
        ->and($run['message'])->toContain('Not enough free space')
        ->and(new BackupRunLog($this->directory)->lastFailedAttempt())->toMatchArray(['status' => 'skipped', 'reason' => 'low_disk_space'])
        ->and(new InstanceLock($this->directory, new RecordingDockerGateway)->current())->toBeNull();
});

it('releases the lock its own container took before a restart, and stops when told', function (): void {
    $lock = new InstanceLock($this->directory, new RecordingDockerGateway);
    $lock->acquire(new LockHolder(ServiceIdentity::OWNER, str_repeat('b', 64), 'proc:1111', 1, 'backup', null), 'backup');
    $daemon = daemon($this, daemonConfig($this->directory, ['schedule' => 'off']));
    $ticks = 0;

    $daemon->loop(function () use (&$ticks): bool {
        return $ticks++ >= 2;
    });

    expect($lock->current())->toBeNull()->and($this->sleeps)->toBe([BackupDaemon::TICK_SECONDS, BackupDaemon::TICK_SECONDS]);
});

it('records the holder of a socket-less service by container ID, boot and init start, which fsa never judges by Docker\'s start time', function (): void {
    $dir = $this->directory->root;
    file_put_contents($dir.'/mountinfo', '123 1 0:1 /var/lib/docker/containers/'.str_repeat('d', 64)."/hostname /etc/hostname rw - ext4 /dev/vda1 rw\n");
    file_put_contents($dir.'/stat', '1 (php (daemon)) S 0 1 1 0 -1 4194560 3000 0 0 0 20 5 0 0 20 0 1 0 987654 1000 200');
    file_put_contents($dir.'/boot_id', "6F1E3C1A-2B4D-4E5F-8A9B-0C1D2E3F4A5B\n");
    $holder = new ServiceIdentity($dir.'/mountinfo', $dir.'/stat', 'host', $dir.'/boot_id')->holder();
    $noBoot = new ServiceIdentity($dir.'/mountinfo', $dir.'/stat', 'host', $dir.'/none')->holder();
    $fallback = new ServiceIdentity($dir.'/none', $dir.'/none', 'abcdef012345', $dir.'/boot_id')->holder();
    $outside = new ServiceIdentity($dir.'/none', $dir.'/none', 'laptop', $dir.'/boot_id')->holder();
    $docker = new RecordingDockerGateway;
    $lock = new InstanceLock($this->directory, $docker);
    $record = $lock->acquire($holder, 'backup')->record;

    // the boot ID makes the init start unique across host reboots too (the ticks alone can repeat after one)
    expect([$holder->owner, $holder->containerId, $holder->containerStartedAt, $holder->hostUid])->toBe([ServiceIdentity::OWNER, str_repeat('d', 64), 'proc:6f1e3c1a-2b4d-4e5f-8a9b-0c1d2e3f4a5b:987654', null])
        ->and($noBoot->containerStartedAt)->toBe('proc:987654')
        ->and([$fallback->containerId, $fallback->containerStartedAt])->toBe(['abcdef012345', null])
        ->and([$outside->containerId, $outside->containerStartedAt])->toBe([null, null])
        ->and($record->startUnknownToDocker())->toBeTrue()
        ->and($lock->isStale($record))->toBeTrue(); // the container is gone
    $docker->withRunningContainer(str_repeat('d', 64), '2026-10-19T01:00:00.5Z');
    expect($lock->isStale($record))->toBeFalse(); // running: its own start time is not Docker's, and that is fine
    $docker->stopContainer(str_repeat('d', 64));
    expect($lock->isStale($record))->toBeTrue();
});

it('records its start with the schedule and removes what an interrupted run left, unless an operation holds the lock', function (): void {
    foreach (['backups/manual-20261018T120000Z.partial', 'backups/'.BackupStore::TOMBSTONE.'scheduled-20261001T033000Z-0a1b2c3d', 'backups/scheduled-20261017T033000Z'] as $left) {
        $this->directory->ensureDirectory($left);
        file_put_contents($this->directory->root.'/'.$left.'/secrets.env', 'APP_KEY=x');
    }
    $store = new BackupStore($this->directory);
    $daemon = daemon($this, daemonConfig($this->directory, ['schedule' => 'off']));
    $heard = [];

    $daemon->loop(function () use (&$heard): bool {
        return count($heard) > 0;
    }, function (array $run) use (&$heard): void {
        $heard[] = $run;
    });
    $ledger = new BackupRunLog($this->directory)->recent();

    expect($store->partials())->toBe([])
        ->and(array_values(array_diff(scandir($this->directory->root.'/backups') ?: [], ['.', '..', 'runs.log'])))->toBe(['scheduled-20261017T033000Z'])
        ->and($heard[0])->toMatchArray(['kind' => BackupAlerts::SERVICE, 'status' => 'started', 'schedule' => 'off', 'time_zone' => 'UTC'])
        ->and($heard[0]['removed'])->toBe(['manual-20261018T120000Z.partial', BackupStore::TOMBSTONE.'scheduled-20261001T033000Z-0a1b2c3d'])
        ->and($ledger[0])->toMatchArray(['kind' => BackupAlerts::SERVICE, 'status' => 'started', 'schedule' => 'off']);

    $this->directory->ensureDirectory('backups/manual-20261019T120000Z.partial');
    $lock = new InstanceLock($this->directory, new RecordingDockerGateway);
    $lock->acquire(TemporaryInstance::holder('c', '2026-10-19T03:00:00Z'), 'upgrade');
    $run = daemonRun($this->directory, daemonConfig($this->directory));

    expect($run->serviceStarted($this->holder))->toMatchArray(['status' => 'started', 'schedule' => '03:30', 'removed' => []])
        ->and($store->partials())->toBe(['manual-20261019T120000Z.partial']) // the next run removes it under the lock
        ->and($lock->current()?->operation)->toBe('upgrade');
});

it('runs a slot missed since the service first started with its schedule at once after a restart, even without a success', function (): void {
    new BackupRunLog($this->directory)->append(['kind' => BackupAlerts::SERVICE, 'status' => 'started', 'started_at' => '2026-10-17T12:00:00Z', 'schedule' => '03:30']);
    $daemon = daemon($this, daemonConfig($this->directory, ['minFreeMib' => 999_999_999]));
    $runs = [];

    $daemon->loop(function () use (&$runs): bool {
        return count($runs) >= 2;
    }, function (array $run) use (&$runs): void {
        $runs[] = $run;
    });

    // the start record, then at once (no sleep) the run of the slot of 2026-10-18 03:30 that was missed
    expect(array_column($runs, 'kind'))->toBe([BackupAlerts::SERVICE, 'scheduled'])
        ->and($runs[1])->toMatchArray(['status' => 'skipped', 'reason' => 'low_disk_space'])
        ->and($this->sleeps)->toBe([]);
});

it('records a failed start once, with its reason, so fsa status and /platform can name it', function (): void {
    config(['database.connections.backup_none' => [...config('database.connections.pgsql'), 'database' => 'fsa_test_no_such_database'], 'database.default' => 'backup_none']);
    $env = ['FSA_INSTANCE_ROOT' => $this->directory->root, 'BACKUP_HMAC_KEY' => DAEMON_KEY, 'FSA_OWNER_UID' => (string) posix_getuid(), 'FSA_OWNER_GID' => (string) posix_getgid(), 'BACKUP_TIME_ZONE' => 'Mars/Olympus'];
    foreach ($env as $key => $value) {
        putenv("{$key}={$value}");
    }
    $call = static function (): array {
        $code = Artisan::call('fsa:backup-daemon', ['--json' => true]);

        return [$code, json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR)];
    };

    try {
        [$zone, $zoneJson] = $call();
        $call(); // Docker restarts it: the same failure again
        putenv('BACKUP_RETENTION=seven');
        [$retention, $retentionJson] = $call();
    } finally {
        foreach ([...array_keys($env), 'BACKUP_RETENTION'] as $key) {
            putenv($key);
        }
    }
    $ledger = new BackupRunLog($this->directory)->recent();

    expect([$zone, $zoneJson['errors'][0]['code']])->toBe([1, 'invalid_schedule'])
        ->and([$retention, $retentionJson['errors'][0]['code']])->toBe([1, 'invalid_backup_config'])
        ->and(array_map(static fn (array $run): array => [$run['kind'], $run['status'], $run['reason'], $run['recorded']], $ledger))->toBe([
            [BackupAlerts::SERVICE, 'failed', 'invalid_backup_config', false],
            [BackupAlerts::SERVICE, 'failed', 'invalid_schedule', false],
        ])
        ->and(BackupAlerts::evaluate($ledger, CarbonImmutable::now())[0]['code'])->toBe('backup_service_failed');
});

it('answers the one-off entry point with one JSON document: lock required, lock lost, low disk space, a seal', function (): void {
    $env = ['FSA_INSTANCE_ROOT' => $this->directory->root, 'BACKUP_HMAC_KEY' => DAEMON_KEY, 'FSA_OWNER_UID' => (string) posix_getuid(), 'FSA_OWNER_GID' => (string) posix_getgid(), 'BACKUP_MIN_FREE_MIB' => '999999999'];
    foreach ($env as $key => $value) {
        putenv("{$key}={$value}");
    }
    $call = static function (array $options): array {
        $code = Artisan::call('fsa:backup-daemon', [...$options, '--json' => true]);

        return [$code, json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR)];
    };

    try {
        [$noLock, $noLockJson] = $call(['--once' => true]);
        [$lost, $lostJson] = $call(['--once' => true, '--lock-id' => str_repeat('a', 32)]);
        $handle = new InstanceLock($this->directory, new RecordingDockerGateway)->acquire(TemporaryInstance::holder('a'), 'backup');
        [$full, $fullJson] = $call(['--once' => true, '--lock-id' => $handle->record->lockId]);
        [$kind, $kindJson] = $call(['--once' => true, '--lock-id' => $handle->record->lockId, '--kind' => 'scheduled']);
        [$unknown, $unknownJson] = $call(['--once' => true, '--lock-id' => $handle->record->lockId, '--kind' => 'nightly']);
        [$seal, $sealJson] = $call(['--seal' => 'manual-20261019T120000Z', '--lock-id' => $handle->record->lockId]);
        putenv('BACKUP_HMAC_KEY=nope');
        [$config, $configJson] = $call(['--once' => true]);
    } finally {
        foreach (array_keys($env) as $key) {
            putenv($key);
        }
    }

    expect([$noLock, $noLockJson['errors'][0]['code']])->toBe([1, 'lock_required'])
        ->and([$lost, $lostJson['errors'][0]['code']])->toBe([1, 'lock_mismatch'])
        ->and([$full, $fullJson['errors'][0]['code']])->toBe([1, 'low_disk_space'])
        ->and($fullJson['errors'][0]['retryable'])->toBeTrue()
        ->and([$kind, $kindJson['errors'][0]['code']])->toBe([1, 'invalid_kind'])
        ->and([$unknown, $unknownJson['errors'][0]['code']])->toBe([1, 'invalid_backup_kind'])
        ->and([$seal, $sealJson['errors'][0]['code']])->toBe([1, 'backup_not_found'])
        ->and([$config, $configJson['errors'][0]['code']])->toBe([1, 'invalid_backup_config'])
        ->and(array_column(new BackupRunLog($this->directory)->recent(), 'reason'))->toBe(['low_disk_space', 'lock_mismatch']);
});

it('seals a plain backup under the caller\'s lock and refuses --include-secrets without secrets on stdin', function (): void {
    $recipient = 'age1'.str_repeat('r', 58);
    $env = ['FSA_INSTANCE_ROOT' => $this->directory->root, 'BACKUP_HMAC_KEY' => DAEMON_KEY, 'FSA_OWNER_UID' => (string) posix_getuid(), 'FSA_OWNER_GID' => (string) posix_getgid(), 'BACKUP_AGE_RECIPIENTS' => $recipient];
    foreach ($env as $key => $value) {
        putenv("{$key}={$value}");
    }
    app()->instance(AgeCli::class, new AgeCli(base_path('tests/Fixtures/bin/fake-age'), base_path('tests/Fixtures/bin/fake-age-keygen')));
    app()->instance(BackupDaemonCommand::STDIN, static fn (): string => '');
    $plain = $this->directory->ensureDirectory('backups/safety-upgrade-20261019T120000Z');
    file_put_contents($plain.'/database.dump', 'PGDMP');
    $handle = new InstanceLock($this->directory, new RecordingDockerGateway)->acquire(TemporaryInstance::holder('a'), 'upgrade');

    try {
        $sealCode = Artisan::call('fsa:backup-daemon', ['--seal' => 'safety-upgrade-20261019T120000Z', '--lock-id' => $handle->record->lockId, '--json' => true]);
        $seal = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $secretsCode = Artisan::call('fsa:backup-daemon', ['--once' => true, '--include-secrets' => true, '--lock-id' => $handle->record->lockId, '--json' => true]);
        $secrets = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        foreach (array_keys($env) as $key) {
            putenv($key);
        }
    }

    expect($sealCode)->toBe(0)->and($seal['data'])->toMatchArray(['backup' => 'safety-upgrade-20261019T120000Z', 'encrypted' => true, 'changed' => true])
        ->and(scandir($plain))->toContain('backup.tar.age')->not->toContain('database.dump')
        ->and($secretsCode)->toBe(1)->and($secrets['errors'][0]['code'])->toBe('secrets_missing');
});

it('reads its configuration from the environment and refuses malformed numbers', function (): void {
    $config = BackupConfig::fromEnvironment([
        'BACKUP_HMAC_KEY' => DAEMON_KEY, 'BACKUP_AGE_RECIPIENTS' => 'age1aaa, age1bbb', 'BACKUP_RETENTION' => '3', 'BACKUP_SCHEDULE' => 'off',
        'DB_DATABASE' => 'form_schema', 'DB_USERNAME' => 'fsa_backup', 'DB_PASSWORD' => 'secret-pw', 'APP_VERSION' => '2.0.0-rc.1',
    ]);

    expect($config->recipients)->toBe(['age1aaa', 'age1bbb'])->and($config->encrypts())->toBeTrue()
        ->and($config->retention)->toBe(3)->and($config->schedule()->enabled())->toBeFalse()
        ->and($config->instanceRoot)->toBe(BackupConfig::INSTANCE_ROOT)->and($config->storagePath)->toBe(BackupConfig::STORAGE_PATH)
        ->and($config->libpqEnvironment())->toMatchArray(['PGHOST' => 'postgres', 'PGPORT' => '5432', 'PGDATABASE' => 'form_schema', 'PGUSER' => 'fsa_backup', 'PGPASSWORD' => 'secret-pw'])
        ->and(print_r($config, true))->not->toContain('secret-pw')
        ->and(fn () => BackupConfig::fromEnvironment(['BACKUP_HMAC_KEY' => DAEMON_KEY, 'BACKUP_RETENTION' => 'seven']))->toThrow(InstanceException::class, 'BACKUP_RETENTION');
});
