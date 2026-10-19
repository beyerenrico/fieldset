<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Backup\SafetyBackup;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Restore\AppKeys;
use App\Domain\Instance\Restore\BackupIntake;
use App\Domain\Instance\Restore\PreparedBackup;
use App\Domain\Instance\Restore\RestoreChecks;
use App\Domain\Instance\Restore\RestoreOperation;
use App\Domain\Instance\Restore\RestoreRequest;
use App\Domain\Instance\Restore\RestoreStack;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;

/**
 * KTD19 step 5 (R57, plan U18, ADR 0084, ADR 0086): the pre-upgrade safety backup through the backup service
 * (SafetyBackup: unencrypted until the upgrade ends, 0600, never pruned), then its verification with the restore's
 * socket-less prepare step (BackupIntake): every checksum, the HMAC under the instance's APP key, the dump's table of
 * contents (`pg_restore --list`) and its migration set. The prepared set is what a rollback restores. A backup that
 * fails its verification stops the upgrade before any migration (exit 9, the previous services running again).
 */
final readonly class UpgradeBackup
{
    public function __construct(
        private InstanceDirectory $directory,
        private SafetyBackup $safety,
        private BackupIntake $intake,
        private RestoreStack $stack,
        private InstanceLock $lock,
    ) {}

    /**
     * @return array{backup: string, path: string, created_at: string|null, prepared: array<string, mixed>}
     *
     * @throws InstanceException exit 9 `safety_backup_failed` or `safety_backup_unverified`
     */
    public function take(InstanceState $state, string $id, int $actorUid): array
    {
        $lock = $this->lock->current() ?? throw new InstanceException('The instance lock was lost during the upgrade.', ExitCode::AbortedUnchanged, 'lock_lost');
        $taken = $this->safety->take($state, UpgradeJournal::OPERATION, new LockHandle($lock));

        return [...$taken, 'prepared' => $this->prepare($state, $taken['backup'], $id, $actorUid)->toArray()];
    }

    /**
     * Prepares (and so verifies) a safety backup into the restore's scratch directory: again for every rollback attempt,
     * because a resumed rollback cannot rely on a scratch directory an earlier run left.
     *
     * @throws InstanceException exit 9 `safety_backup_unverified`
     */
    public function prepare(InstanceState $state, string $backup, string $id, int $actorUid): PreparedBackup
    {
        try {
            $intake = $this->intake->take(RestoreOperation::runAs($state), new RestoreRequest($backup, actorUid: $actorUid), RestoreChecks::IN_PLACE, $id, AppKeys::of($this->stack->secrets()->appKeys()), $state->version());
        } catch (InstanceException $e) {
            throw new InstanceException("The safety backup {$backup} failed its verification: ".$e->getMessage(), ExitCode::AbortedUnchanged, 'safety_backup_unverified',
                'Nothing was migrated; the previous version runs again. Check free space and `fsa logs backup`, then run `fsa upgrade` again.', true, ['reason' => $e->errorCode], $e);
        }

        return $intake['backup'];
    }

    /**
     * The audit chain heads a safety backup recorded (`audit-heads.json`, ADR 0084): the final heads of the database it
     * was taken from, since writers were stopped and pending entries sealed before it.
     *
     * @return array{epoch: int, heads: array<string, mixed>}
     */
    public function heads(string $backup): array
    {
        $raw = $this->directory->read(InstanceDirectory::BACKUPS_DIR.'/'.$backup.'/audit-heads.json');
        $heads = is_string($raw) ? json_decode($raw, true) : null;

        return ['epoch' => is_array($heads) && is_int($heads['epoch'] ?? null) ? $heads['epoch'] : 1, 'heads' => is_array($heads) && is_array($heads['heads'] ?? null) ? $heads['heads'] : []];
    }
}
