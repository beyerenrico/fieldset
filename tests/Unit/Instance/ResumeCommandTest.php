<?php

declare(strict_types=1);

use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationUnfinished;
use App\Domain\Instance\Operations\ResumeCommand;

/*
 * The command that resumes an unfinished operation, rebuilt from its journal context (M12 gate O1, O2/C7): the backup, the
 * identity file, where the passphrase came from, the APP key file, `--accept-unauthenticated` and `--yes` where the
 * command asks first. Secrets never: only the paths and switches the journal recorded.
 */

/** @param  array<string, mixed>  $context */
function resumeRecord(string $operation, array $context): JournalRecord
{
    return JournalRecord::start('0123456789abcdef', $operation, 1000, $context, '2026-10-20T08:00:00Z');
}

it('rebuilds a restore with the identity file, the passphrase file, the APP key file, the unauthenticated switch and --yes', function (): void {
    $record = resumeRecord('restore', ['backup' => 'manual-20261019T120000Z', 'accept_unauthenticated' => true, 'identity_file' => '/srv/keys/identity.age',
        'key_file' => true, 'passphrase_file' => '/srv/keys/pass phrase.txt', 'passphrase_stdin' => false, 'app_key_file' => '/srv/keys/app.env']);

    expect(ResumeCommand::line($record))->toBe("fsa restore manual-20261019T120000Z --accept-unauthenticated --identity-file /srv/keys/identity.age --passphrase-file '/srv/keys/pass phrase.txt' --app-key-file /srv/keys/app.env --yes")
        ->and(ResumeCommand::options($record))->toBe(['--accept-unauthenticated' => true, '--identity-file' => '/srv/keys/identity.age', '--passphrase-file' => '/srv/keys/pass phrase.txt', '--app-key-file' => '/srv/keys/app.env', '--yes' => true])
        ->and(OperationUnfinished::commandLine($record))->toBe(ResumeCommand::line($record))
        ->and(OperationUnfinished::options($record))->toBe(ResumeCommand::options($record));
});

it('names --passphrase-stdin, and placeholders where a journal of an earlier build recorded no path', function (): void {
    $stdin = resumeRecord('restore', ['backup' => 'b', 'identity_file' => '/k/id.age', 'passphrase_stdin' => true]);
    $legacy = resumeRecord('restore', ['backup' => 'b', 'accept_unauthenticated' => false, 'identity_file' => '/k/id.age', 'key_file' => true]);
    $plain = resumeRecord('restore', ['backup' => 'b', 'accept_unauthenticated' => false, 'identity_file' => null, 'key_file' => false]);

    expect(ResumeCommand::line($stdin))->toBe('fsa restore b --identity-file /k/id.age --passphrase-stdin --yes')
        ->and(ResumeCommand::line($legacy))->toBe('fsa restore b --identity-file /k/id.age --passphrase-file <file> --app-key-file <file> --yes')
        ->and(ResumeCommand::line($plain))->toBe('fsa restore b --yes');
});

it('rebuilds a rollback with the identity and passphrase flags it needs and no question', function (): void {
    expect(ResumeCommand::line(resumeRecord('rollback', ['identity_file' => '/k/id.age', 'passphrase_file' => '/k/pass'])))->toBe('fsa rollback --identity-file /k/id.age --passphrase-file /k/pass')
        ->and(ResumeCommand::line(resumeRecord('rollback', ['identity_file' => null])))->toBe('fsa rollback');
});

it('rebuilds an install from a backup as `fsa install --from-backup`, never as a plain install (O1)', function (): void {
    $record = resumeRecord('install', ['domain' => null, 'from_backup' => '/srv/old/backups/manual-1', 'accept_unauthenticated' => false, 'identity_file' => null, 'key_file' => true, 'app_key_file' => '/srv/app-key']);

    expect(ResumeCommand::line($record))->toBe('fsa install --from-backup /srv/old/backups/manual-1 --app-key-file /srv/app-key')
        ->and(OperationUnfinished::commandLine($record))->toBe('fsa install --from-backup /srv/old/backups/manual-1 --app-key-file /srv/app-key')
        ->and(OperationUnfinished::commandLine(resumeRecord('install', ['domain' => null])))->toBe('fsa install');
});

it('rebuilds an adopt with its checkout, release, --drop-jobs and --yes (O9)', function (): void {
    expect(ResumeCommand::line(resumeRecord('adopt', ['checkout' => '/srv/form schema', 'release' => '2.0.0', 'drop_jobs' => 1])))->toBe("fsa adopt '/srv/form schema' --release 2.0.0 --drop-jobs --yes")
        ->and(ResumeCommand::line(resumeRecord('adopt', ['checkout' => '/srv/fsa', 'release' => null, 'drop_jobs' => 0])))->toBe('fsa adopt /srv/fsa --yes');
});

it('leaves the other operations to the existing rules', function (): void {
    expect(ResumeCommand::applies(resumeRecord('config', [])))->toBeFalse()
        ->and(ResumeCommand::applies(resumeRecord('install', ['domain' => null])))->toBeFalse()
        ->and(ResumeCommand::applies(resumeRecord('restore', ['backup' => 'b'])))->toBeTrue();
});
