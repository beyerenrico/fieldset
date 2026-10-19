<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use InvalidArgumentException;

/**
 * The safety backup that upgrade, restore, reset, rollback and adopt take before they change anything (R57, KTD19,
 * ADR 0084; their flows are plan U17–U19, U23). `take()` writes `backups/safety-<operation>-<time>/` through the backup
 * service under the caller's instance lock: unencrypted (a rollback restores it without the owner's identity), 0600,
 * verified (checksums, `pg_restore --list`) and never pruned by retention. Once the operation completed, `seal()`
 * encrypts it to the recipients when encryption is on. A failed safety backup changed nothing: exit 9, retryable.
 */
final readonly class SafetyBackup
{
    public function __construct(private InstanceDirectory $directory, private BackupRunner $runner) {}

    /**
     * @return array{backup: string, path: string, bytes: int|null, created_at: string|null}
     *
     * @throws InstanceException exit 9 `safety_backup_failed` (the operation must stop before its first change)
     */
    public function take(InstanceState $state, string $operation, LockHandle $lock): array
    {
        if (! in_array($operation, BackupName::SAFETY_OPERATIONS, true)) {
            throw new InvalidArgumentException("No safety backup for the operation [{$operation}].");
        }
        $outcome = $this->runner->backup($this->project($state), BackupName::SAFETY.'-'.$operation, $lock->record->lockId);
        if (! $outcome->ok) {
            throw new InstanceException(
                "The safety backup before {$operation} failed: ".$outcome->errorMessage(),
                ExitCode::AbortedUnchanged,
                'safety_backup_failed',
                'Fix the cause (`fsa logs backup`, free disk space), then run the command again; nothing was changed.',
                true,
                ['reason' => $outcome->errorCode(), 'output' => $outcome->output],
            );
        }
        $name = (string) ($outcome->data['backup'] ?? '');
        $details = is_array($outcome->data['result'] ?? null) ? $outcome->data['result'] : [];

        return [
            'backup' => $name, 'path' => $this->directory->root.'/'.InstanceDirectory::BACKUPS_DIR.'/'.$name,
            'bytes' => is_int($outcome->data['bytes'] ?? null) ? $outcome->data['bytes'] : null,
            'created_at' => is_string($details['created_at'] ?? null) ? $details['created_at'] : null,
        ];
    }

    /**
     * Encrypts the safety backup once its operation completed, when the instance has recipients; else nothing.
     *
     * @return array{backup: string, encrypted: bool, changed: bool}
     *
     * @throws InstanceException exit 4 `safety_backup_seal_failed`: the backup stays, unencrypted, 0600
     */
    public function seal(InstanceState $state, string $backup, LockHandle $lock): array
    {
        if ($state->recipients() === []) {
            return ['backup' => $backup, 'encrypted' => false, 'changed' => false];
        }
        $outcome = $this->runner->seal($this->project($state), $backup, $lock->record->lockId);
        if (! $outcome->ok) {
            throw new InstanceException("Encrypting the safety backup {$backup} failed: ".$outcome->errorMessage(), ExitCode::HealthCheckFailed, 'safety_backup_seal_failed', 'It stays unencrypted (0600) in backups/; encrypt or delete it by hand.', true, ['reason' => $outcome->errorCode()]);
        }

        return ['backup' => $backup, 'encrypted' => true, 'changed' => ($outcome->data['changed'] ?? false) === true];
    }

    private function project(InstanceState $state): ComposeProject
    {
        return ComposeProject::forInstance($state, $this->directory);
    }
}
