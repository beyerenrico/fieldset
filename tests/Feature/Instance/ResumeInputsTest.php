<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockHolder;
use App\Domain\Instance\Operations\OperationJournal;
use Tests\Support\Instance\FromBackupFixture;
use Tests\Support\Instance\InterruptedRestore;
use Tests\Support\Instance\RestoreFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Resuming with the right command and inputs (M12 gate O1, O2/C7, C11): an install journal begun by `--from-backup` resumes
 * only as that command (a plain `fsa install` exits 9 and names it); every resume line names the inputs the resumed run
 * needs; a resumed restore or rollback without one exits 2 before the lock instead of taking the way back; `fsa login-link`
 * issues its link next to a running backup.
 */

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

it('refuses a plain fsa install over an unfinished install from a backup and names the --from-backup command (O1)', function (): void {
    $move = new FromBackupFixture;
    $backup = $move->backup();
    $keyFile = $move->keyFile();
    $move->pgRestore = new ProcessOutcome(1, '', 'pg_restore: error: connection lost');
    [$failed] = $move->install($backup, ['--app-key-file' => $keyFile]);
    $journal = new OperationJournal($move->instance->directory);
    $phases = count($journal->current()?->phases() ?? []);
    $move->pgRestore = null;

    [$code, $json] = $move->instance->run('fsa:install', ['--org' => 'Acme', '--owner-email' => 'owner@example.com']);
    [, $status] = $move->instance->run('fsa:status');
    [$resumed, $resumedJson] = $move->install($backup, ['--app-key-file' => $keyFile]);

    expect($failed)->toBe(4)
        ->and($code)->toBe(9, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('operation_unfinished')
        ->and($json['data']['command'])->toBe("fsa install --from-backup {$backup} --app-key-file {$keyFile}")
        ->and($json['errors'][0]['hint'])->toContain('a plain `fsa install` cannot take it over')
        ->and($status['data']['operation']['command'])->toBe("fsa install --from-backup {$backup} --app-key-file {$keyFile}")
        ->and($move->instance->oneOffCalls('app:install'))->toBe([])
        ->and($resumed)->toBe(0, (string) json_encode($resumedJson))
        ->and(array_column($move->instance->state()->history(), 'event'))->toContain('installed_from_backup');
    $move->cleanup();
    expect($phases)->toBeGreaterThan(0);
});

it('refuses a resumed install from a backup without the APP key file it began with (exit 2, the journal stays open)', function (): void {
    $move = new FromBackupFixture;
    $backup = $move->backup();
    $move->pgRestore = new ProcessOutcome(1, '', 'pg_restore: error: connection lost');
    $move->install($backup, ['--app-key-file' => $move->keyFile()]);
    $move->pgRestore = null;
    $mark = count($move->instance->docker->calls);

    [$code, $json] = $move->install($backup);

    expect($code)->toBe(2, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('app_key_required')
        ->and($json['data']['command'])->toContain('--app-key-file')
        ->and(array_slice($move->instance->docker->calls, $mark))->toBe([])
        ->and(new OperationJournal($move->instance->directory)->current()?->operation())->toBe('install');
    $move->cleanup();
});

it('records where the passphrase and the APP keys came from, never their values, so the resume line repeats the flags (O2)', function (): void {
    $restore = new RestoreFixture;
    $restore->backup();
    $outside = sys_get_temp_dir().'/fsa-test-keys-'.bin2hex(random_bytes(4));
    mkdir($outside, 0700);
    file_put_contents($outside.'/app.env', $restore->appKey()."\n");
    $restore->pgRestore = new ProcessOutcome(1, '', 'pg_restore: error: relation exists');

    [$code] = $restore->restore('manual-20261019T120000Z', ['--app-key-file' => $outside.'/app.env', '--accept-unauthenticated' => true]);
    $context = $restore->config->archivedJournal('restore')->context();
    exec('rm -rf '.escapeshellarg($outside));

    expect($code)->toBe(5)
        ->and($context)->toMatchArray(['backup' => 'manual-20261019T120000Z', 'accept_unauthenticated' => true, 'key_file' => true, 'app_key_file' => $outside.'/app.env', 'passphrase_file' => null, 'passphrase_stdin' => false])
        ->and(json_encode($context))->not->toContain($restore->appKey());
});

it('refuses a resumed restore without the identity file, the passphrase or the unauthenticated switch of the first run: exit 2, no way back (O2)', function (string $missing, array $context, string $error): void {
    $restore = new RestoreFixture;
    $restore->backup();
    $journal = new OperationJournal($restore->instance->directory);
    InterruptedRestore::journal($restore, 'safety_backup');
    $record = $journal->current();
    $document = $record?->toArray() ?? [];
    $document['context'] = [...$document['context'], ...$context];
    $restore->instance->directory->write(OperationJournal::CURRENT, json_encode($document, JSON_THROW_ON_ERROR));
    $mark = count($restore->instance->docker->calls);

    [$code, $json] = $restore->restore();

    expect($code)->toBe(2, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe($error)
        ->and($json['errors'][0]['message'])->toContain($missing)
        ->and($json['changed'])->toBeFalse()
        ->and(array_slice($restore->instance->docker->calls, $mark))->toBe([])
        ->and(InterruptedRestore::gateRaised($restore->instance->directory->root))->toBeFalse() // untouched: the fixture's gate never went up
        ->and($journal->current()?->phases())->toBe($record?->phases());
})->with([
    'identity file' => ['--identity-file', ['identity_file' => '/srv/keys/id.age', 'passphrase_file' => '/srv/keys/pass'], 'identity_required'],
    'unauthenticated switch' => ['--accept-unauthenticated', ['accept_unauthenticated' => true], 'backup_unauthenticated'],
    'APP key file' => ['--app-key-file', ['key_file' => true, 'app_key_file' => '/srv/keys/app.env'], 'app_key_required'],
    'without files' => ['--without-files', ['without_files' => true], 'backup_without_files'],
]);

it('lets a resumed restore past its swap continue without the inputs: it prepares nothing again', function (): void {
    $restore = new RestoreFixture;
    $restore->backup();
    $names = InterruptedRestore::journal($restore, 'verify');
    InterruptedRestore::swapped($restore, $names);
    $journal = new OperationJournal($restore->instance->directory);
    $document = $journal->current()?->toArray() ?? [];
    $document['context']['identity_file'] = '/srv/keys/id.age';
    $restore->instance->directory->write(OperationJournal::CURRENT, json_encode($document, JSON_THROW_ON_ERROR));

    [$code, $json] = $restore->restore();

    expect($code)->toBe(0, (string) json_encode($json))->and($journal->current())->toBeNull();
});

it('refuses a resumed rollback without the passphrase source of the first run (exit 2 before the lock)', function (): void {
    $restore = new RestoreFixture;
    $journal = new OperationJournal($restore->instance->directory);
    $journal->begin('rollback', posix_getuid(), ['identity_file' => '/srv/keys/id.age', 'passphrase_file' => '/srv/keys/pass', 'passphrase_stdin' => false]);
    $journal->startPhase('plan', [], false);
    $journal->completePhase('plan', ['names' => []]);
    $journal->startPhase('quiesce');
    $journal->completePhase('quiesce', ['gate' => 'raised']);
    $mark = count($restore->instance->docker->calls);

    [$code, $json] = $restore->instance->run('fsa:rollback', ['--yes' => true]);

    expect($code)->toBe(2, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('identity_required')
        ->and($json['data']['command'])->toBe('fsa rollback --identity-file /srv/keys/id.age --passphrase-file /srv/keys/pass')
        ->and(array_slice($restore->instance->docker->calls, $mark))->toBe([])
        ->and($journal->current()?->lastStartedPhase())->toBe('quiesce');
});

it('issues a sign-in link while a backup holds the instance lock, but not while another operation does (C11)', function (string $operation, int $expected): void {
    $restore = new RestoreFixture;
    $lock = app(InstanceLock::class);
    $handle = $lock->acquire(new LockHolder('backup-daemon', null, null, 4242, 'fsa-test', posix_getuid()), $operation);

    [$code, $json] = $restore->instance->run('fsa:login-link', ['email' => 'owner@example.com', '--secrets-file' => $restore->instance->directory->root.'/link.json']);
    $lock->release($handle);

    expect($code)->toBe($expected, (string) json_encode($json))
        ->and($expected === 0 ? $json['data']['email'] : $json['errors'][0]['code'])->toBe($expected === 0 ? 'owner@example.com' : 'instance_locked');
})->with(['a backup' => ['backup', 0], 'a config change' => ['config', 9]]);
