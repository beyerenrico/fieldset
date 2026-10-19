<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Backup\BackupEntry;
use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\Backup\SafetyBackup;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Operations\LockRecord;
use App\Domain\Instance\Restore\RestoreDatabases;
use App\Domain\Instance\Restore\RestoreNames;
use App\Domain\Instance\Restore\RestoreSource;
use App\Domain\Instance\Restore\RestoreStorage;
use App\Domain\Instance\State\InstanceDirectory;
use Throwable;

/**
 * The last phase of a completed automatic rollback (plan U18, R57, ADR 0086): the database the upgrade migrated (put
 * aside by the swap) and the storage volume the instance used before (superseded by the restored one) go, with the
 * stage and the scratch directory; the safety backup is sealed when encryption is on; the state's history records the
 * rollback once. Nothing here can undo the rollback: what fails is a warning naming what is left.
 */
final readonly class UpgradeRollbackCleanup
{
    public function __construct(
        private InstanceDirectory $directory,
        private RestoreDatabases $databases,
        private RestoreStorage $storage,
        private RestoreSource $source,
        private BackupStore $store,
        private SafetyBackup $safety,
        private InstanceLock $lock,
    ) {}

    /**
     * @param  array<string, mixed>  $release
     * @return array{left_behind: list<string>, sealed: bool, warnings: list<array{0: string, 1: string, 2: string|null}>}
     */
    public function run(ComposeProject $project, RestoreNames $names, ?string $backup, JournalRecord $record, array $release): array
    {
        $left = [];
        $warnings = [];
        try {
            $this->databases->drop($project, $names->aside);
        } catch (Throwable) {
            $left[] = "database {$names->aside}";
        }
        if ($names->currentVolume !== $names->freshVolume && ! $this->storage->remove($names->currentVolume)) {
            $left[] = "volume {$names->currentVolume}";
        }
        UpgradeFiles::remove($this->directory->path(InstanceDirectory::OPERATIONS_DIR.'/upgrade-'.$names->id));
        try {
            $this->source->discard($names->id);
        } catch (Throwable) {
            $left[] = 'directory backups/'.RestoreSource::SCRATCH_PREFIX.$names->id;
        }
        $sealed = $this->seal($backup, $warnings);
        $this->record($backup, $record, $release);
        if ($left !== []) {
            $warnings[] = ['rollback_cleanup_incomplete', 'The rollback succeeded, but these could not be removed: '.implode(', ', $left).'.', 'Remove them by hand (`docker volume rm`, `DROP DATABASE`).'];
        }

        return ['left_behind' => $left, 'sealed' => $sealed, 'warnings' => $warnings];
    }

    /** @param  list<array{0: string, 1: string, 2: string|null}>  $warnings */
    private function seal(?string $backup, array &$warnings): bool
    {
        $lock = $this->lock->current();
        if ($backup === null || ! $lock instanceof LockRecord || ! $this->store->find($backup) instanceof BackupEntry) {
            return false;
        }
        try {
            return $this->safety->seal($this->directory->state(), $backup, new LockHandle($lock))['encrypted'];
        } catch (Throwable $e) {
            $warnings[] = ['safety_backup_not_sealed', "The safety backup {$backup} stays unencrypted (0600): ".$e->getMessage(), 'Encrypt or delete it by hand.'];

            return false;
        }
    }

    /** @param  array<string, mixed>  $release */
    private function record(?string $backup, JournalRecord $record, array $release): void
    {
        $state = $this->directory->state();
        $history = $state->history();
        $last = $history === [] ? [] : $history[array_key_last($history)];
        if (($last['event'] ?? null) === 'upgrade_rolled_back' && ($last['journal'] ?? null) === $record->id()) {
            return; // a resumed cleanup: recorded already
        }
        $this->directory->saveState($state->withHistoryEntry('upgrade_rolled_back', [
            'from' => is_string($release['from'] ?? null) ? $release['from'] : null, 'to' => is_string($release['version'] ?? null) ? $release['version'] : null,
            'journal' => $record->id(), 'safety_backup' => $backup,
        ]));
    }
}
