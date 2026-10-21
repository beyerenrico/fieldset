<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\AgeCli;
use App\Domain\Instance\Backup\BackupRunner;
use App\Domain\Instance\Backup\SafetyBackup;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockRecord;
use App\Domain\Instance\State\EnvFiles;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * `fsa backup` and the safety-backup entry point on the ops side (R38, R40, R57, KTD25, plan U16, ADR 0084), against the
 * recording Docker gateway: Postgres up, the backup role set up in a socket-less one-off of the app service (password by
 * name), then the backup service's own entry point as a one-off of that service under the instance lock fsa holds.
 */

beforeEach(function (): void {
    $this->config = new ConfigFixture;
    $this->instance = $this->config->instance;
    $this->instance->withRunningStack();
    $this->outside = sys_get_temp_dir().'/fsa-test-backup-'.bin2hex(random_bytes(4));
    mkdir($this->outside, 0700);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->outside));
    TemporaryInstance::cleanup();
});

/** @return list<array<string, mixed>> the backup service's one-offs */
function backupOneOffs(InstanceCommandFixture $instance): array
{
    return array_values(array_filter($instance->docker->called('composeRun'), static fn (array $call): bool => $call['service'] === 'backup'));
}

it('runs the backup service once under the lock it holds, after the backup role, and reports the backup', function (): void {
    $held = null;
    $this->instance->docker->onCall['composeRun'] = function (array $args) use (&$held): void {
        if ($args['service'] === 'backup') {
            $held = new InstanceLock($this->instance->directory, $this->instance->docker)->current();
        }
    };
    $mark = $this->config->mark();

    [$code, $json] = $this->instance->run('fsa:backup');
    $role = array_values(array_filter($this->config->callsSince($mark, 'composeRun'), static fn (array $call): bool => ($call['command'][2] ?? null) === 'instance:backup-role'));
    $run = backupOneOffs($this->instance)[0];

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toMatchArray([
            'backup' => 'manual-20261019T120000Z', 'path' => $this->instance->directory->root.'/backups/manual-20261019T120000Z', 'kind' => 'manual',
            'bytes' => 52_431, 'encrypted' => false, 'includes_secrets' => false, 'app_key_fingerprint' => '0123-4567-89ab-cdef',
        ])
        ->and($json['changed'])->toBeTrue()
        ->and(collect($json['warnings'])->pluck('code')->all())->toBe(['backup_on_host'])
        ->and($role)->toHaveCount(1)->and($role[0]['env'])->toBe(['FIELDSET_BACKUP_DB_PASSWORD'])
        ->and($run['command'])->toBe(['fsa', 'backup-daemon', '--once', '--kind=manual', '--lock-id='.$held?->lockId, '--json'])
        ->and($held)->toBeInstanceOf(LockRecord::class)->and($held?->operation)->toBe('backup')
        ->and(new InstanceLock($this->instance->directory, $this->instance->docker)->current())->toBeNull()
        ->and($this->config->methodsSince($mark))->not->toContain('composeUp')
        ->and(json_encode($json))->not->toContain((string) new EnvFiles($this->instance->directory)->read('backup')['DB_PASSWORD']);
});

it('prints the APP_KEY fingerprint unredacted: it names the key without being a secret (R67)', function (): void {
    $fingerprint = new EnvFiles($this->instance->directory)->read('backup')['BACKUP_APP_FINGERPRINTS'];
    $run = json_decode(trim(InstanceCommandFixture::backupRun()->stdout), true, 512, JSON_THROW_ON_ERROR);
    $run['data']['result']['app_key_fingerprints'] = [$fingerprint];
    $this->instance->backupOutcome = InstanceCommandFixture::oneOffJson($run);

    [$code, $json] = $this->instance->run('fsa:backup');

    expect($code)->toBe(0)->and($fingerprint)->toMatch('/^[0-9a-f]{4}(-[0-9a-f]{4}){3}$/')
        ->and($json['data']['app_key_fingerprint'])->toBe($fingerprint);
});

it('starts Postgres first when the instance is stopped', function (): void {
    foreach ($this->instance->docker->containers as $id => $container) {
        $this->instance->docker->stopContainer((string) $id);
    }
    $mark = $this->config->mark();

    [$code] = $this->instance->run('fsa:backup');

    expect($code)->toBe(0)->and($this->config->callsSince($mark, 'composeUp')[0]['services'])->toBe(['postgres']);
});

it('maps a failed run to exit 3 for low disk space and exit 4 otherwise, leaving the lock free', function (string $reason, int $exit, bool $retryable): void {
    $this->instance->backupOutcome = InstanceCommandFixture::oneOffJson(['command' => 'backup-daemon', 'status' => 'failed', 'errors' => [['code' => $reason, 'message' => 'it went wrong', 'hint' => null, 'retryable' => true]]]);

    [$code, $json] = $this->instance->run('fsa:backup');

    expect($code)->toBe($exit)
        ->and($json['errors'][0]['code'])->toBe($reason)
        ->and($json['errors'][0]['retryable'])->toBe($retryable)
        ->and($json['errors'][0]['message'])->toContain('it went wrong')
        ->and(new InstanceLock($this->instance->directory, $this->instance->docker)->current())->toBeNull();
})->with([
    'low disk space' => ['low_disk_space', 3, false],
    'pg_dump' => ['pg_dump_failed', 4, true],
    'lost lock' => ['lock_mismatch', 9, false],
]);

it('reports a one-off without a result and a failed role set-up', function (): void {
    $this->instance->backupOutcome = new ProcessOutcome(137, '', 'Killed');
    [$code, $json] = $this->instance->run('fsa:backup');
    expect($code)->toBe(4)->and($json['errors'][0]['code'])->toBe('one_off_failed');

    $this->instance->oneOffs['instance:backup-role'] = InstanceCommandFixture::oneOffJson(['command' => 'instance:backup-role', 'status' => 'failed', 'errors' => [['code' => 'backup_role_failed', 'message' => 'no superuser', 'hint' => null, 'retryable' => false]]]);
    [$roleCode, $role] = $this->instance->run('fsa:backup');
    expect($roleCode)->toBe(4)->and($role['errors'][0]['code'])->toBe('backup_role_failed');
});

it('refuses while another operation holds the lock, without starting anything', function (): void {
    $this->instance->docker->withRunningContainer(str_repeat('e', 64), '2026-10-19T09:00:00Z');
    new InstanceLock($this->instance->directory, $this->instance->docker)->acquire(TemporaryInstance::holder('e', '2026-10-19T09:00:00Z'), 'upgrade');
    $mark = $this->config->mark();

    [$code, $json] = $this->instance->run('fsa:backup');

    expect($code)->toBe(9)->and($json['errors'][0]['code'])->toBe('instance_locked')
        ->and(array_filter($this->config->callsSince($mark, 'composeRun')))->toBe([]);
});

it('refuses --include-secrets without encryption, and passes the secrets on stdin only when encryption is on', function (): void {
    [$code, $json] = $this->instance->run('fsa:backup', ['--include-secrets' => true]);
    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('encryption_required')->and(backupOneOffs($this->instance))->toBe([]);

    $this->instance->directory->saveState($this->instance->state()->withRecipients(['age1'.str_repeat('c', 58)]));
    [$encryptedCode] = $this->instance->run('fsa:backup', ['--include-secrets' => true]);
    $app = new EnvFiles($this->instance->directory)->read('app');
    $run = backupOneOffs($this->instance)[0];

    expect($encryptedCode)->toBe(0)
        ->and($run['command'])->toContain('--include-secrets')
        ->and(implode(' ', $run['command']))->not->toContain($app['APP_KEY'])
        ->and($this->instance->backupInput)->toContain('APP_KEY=')->toContain($app['APP_KEY'])->not->toContain('DB_PASSWORD');
});

it('turns encryption on first with --encrypt, then backs up', function (): void {
    app()->instance(AgeCli::class, new AgeCli(base_path('tests/Fixtures/bin/fake-age'), base_path('tests/Fixtures/bin/fake-age-keygen')));
    file_put_contents($this->outside.'/pass', 'a long enough passphrase');

    [$missing, $missingJson] = $this->instance->run('fsa:backup', ['--encrypt' => true]);
    [$code, $json] = $this->instance->run('fsa:backup', ['--encrypt' => true, '--identity-out' => $this->outside.'/id.age', '--passphrase-file' => $this->outside.'/pass']);
    [$againCode, $again] = $this->instance->run('fsa:backup', ['--encrypt' => true]);
    [$strayCode, $stray] = $this->instance->run('fsa:backup', ['--identity-out' => $this->outside.'/other.age']);

    expect($missing)->toBe(2)->and($missingJson['errors'][0]['code'])->toBe('identity_required')
        ->and($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['encryption']['enabled'])->toBeTrue()
        ->and($json['data']['encryption']['recipients'])->toBe($this->instance->state()->recipients())
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('backup_identity_written')
        ->and(file_exists($this->outside.'/id.age'))->toBeTrue()
        ->and($againCode)->toBe(0)->and($again['data'])->not->toHaveKey('encryption')
        ->and($strayCode)->toBe(2)->and($stray['errors'][0]['code'])->toBe('invalid_flag');
});

it('refuses an instance without a backup service or not installed yet', function (): void {
    $compose = json_decode((string) preg_replace('/\A(#[^\n]*\n)+/', '', (string) $this->instance->directory->read('compose.yaml')), true);
    unset($compose['services']['backup']);
    $this->instance->directory->write('compose.yaml', json_encode($compose), 0600);

    [$code, $json] = $this->instance->run('fsa:backup');
    $fresh = new InstanceCommandFixture;
    [$freshCode, $freshJson] = $fresh->run('fsa:backup');

    expect($code)->toBe(3)->and($json['errors'][0]['code'])->toBe('backup_service_missing')
        ->and($freshCode)->toBe(2)->and($freshJson['errors'][0]['code'])->toBe('not_installed');
});

it('takes a safety backup under the caller\'s lock and seals it once the operation completed', function (): void {
    $lock = new InstanceLock($this->instance->directory, $this->instance->docker);
    $handle = $lock->acquire(TemporaryInstance::holder('f', '2026-10-19T09:00:00Z'), 'upgrade');
    $safety = app(SafetyBackup::class);

    $taken = $safety->take($this->instance->state(), 'upgrade', $handle);
    $plain = $safety->seal($this->instance->state(), $taken['backup'], $handle);
    $this->instance->directory->saveState($this->instance->state()->withRecipients(['age1'.str_repeat('d', 58)]));
    $this->instance->backupOutcome = InstanceCommandFixture::oneOffJson(['command' => 'backup-daemon', 'status' => 'ok', 'changed' => true, 'data' => ['backup' => $taken['backup'], 'encrypted' => true, 'changed' => true, 'bytes' => 9]]);
    $sealed = $safety->seal($this->instance->state(), $taken['backup'], $handle);
    $calls = backupOneOffs($this->instance);

    expect($taken)->toMatchArray(['backup' => 'safety-upgrade-20261019T120000Z', 'bytes' => 52_431, 'created_at' => '2026-10-19T12:00:00Z'])
        ->and($calls[0]['command'])->toBe(['fsa', 'backup-daemon', '--once', '--kind=safety-upgrade', '--lock-id='.$handle->record->lockId, '--json'])
        ->and($plain)->toBe(['backup' => $taken['backup'], 'encrypted' => false, 'changed' => false])
        ->and($sealed)->toBe(['backup' => $taken['backup'], 'encrypted' => true, 'changed' => true])
        ->and($calls[1]['command'])->toBe(['fsa', 'backup-daemon', '--seal='.$taken['backup'], '--lock-id='.$handle->record->lockId, '--json'])
        ->and(fn () => $safety->take($this->instance->state(), 'install', $handle))->toThrow(InvalidArgumentException::class);

    $this->instance->backupOutcome = InstanceCommandFixture::oneOffJson(['command' => 'backup-daemon', 'status' => 'failed', 'errors' => [['code' => 'pg_dump_failed', 'message' => 'boom', 'hint' => null, 'retryable' => true]]]);
    expect(fn () => $safety->take($this->instance->state(), 'restore', $handle))->toThrow(function (InstanceException $e): void {
        expect($e->exitCode->value)->toBe(9)->and($e->errorCode)->toBe('safety_backup_failed')->and($e->retryable)->toBeTrue();
    })->and(fn () => $safety->seal($this->instance->state(), $taken['backup'], $handle))->toThrow(InstanceException::class, 'Encrypting the safety backup');
});

it('sets up the backup role with the password of env/backup.env, and refuses without one', function (): void {
    $runner = app(BackupRunner::class);
    $project = ComposeProject::forInstance($this->instance->state(), $this->instance->directory);
    $runner->ensureRole($project);
    new EnvFiles($this->instance->directory)->write('backup', ['DB_USERNAME' => 'fieldset_backup']);

    expect($this->instance->oneOffCalls('instance:backup-role')[0]['env'])->toBe(['FIELDSET_BACKUP_DB_PASSWORD'])
        ->and(fn () => $runner->ensureRole($project))->toThrow(InstanceException::class, 'no password for the backup role');
});
