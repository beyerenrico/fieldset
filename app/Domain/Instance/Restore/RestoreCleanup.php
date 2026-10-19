<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Backup\SafetyBackup;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Operations\LockRecord;
use App\Domain\Instance\State\InstanceDirectory;
use Throwable;

/**
 * The last phase of a verified restore (plan U17 step 11, R57, ADR 0085): only now do the put-aside database and the
 * replaced storage volume go, with the scratch directory; the safety backup is encrypted to the recipients when
 * encryption is on, and the state records the restore (`fsa status`, `config show`: last restore). Nothing here can
 * undo the restore: what fails is reported as a warning naming what is left.
 */
final readonly class RestoreCleanup
{
    public function __construct(
        private InstanceDirectory $directory,
        private InstanceLock $lock,
        private RestoreDatabases $databases,
        private RestoreStorage $storage,
        private RestoreSource $source,
        private SafetyBackup $safety,
    ) {}

    /** @return array{left_behind: list<string>, sealed: bool, warnings: list<array{0: string, 1: string, 2: string|null}>} */
    public function run(ComposeProject $project, RestoreNames $names, ?string $safetyBackup, JournalRecord $record, ?string $backup): array
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
        try {
            $this->source->discard($names->id);
        } catch (Throwable) {
            $left[] = 'directory backups/'.RestoreSource::SCRATCH_PREFIX.$names->id;
        }
        $sealed = $this->seal($safetyBackup, $warnings);
        $this->record($backup, $safetyBackup, $record);
        if ($left !== []) {
            $warnings[] = ['restore_cleanup_incomplete', 'The restore succeeded, but these could not be removed: '.implode(', ', $left).'.', 'Remove them by hand once you checked the restored instance (`docker volume rm`, `DROP DATABASE`).'];
        }

        return ['left_behind' => $left, 'sealed' => $sealed, 'warnings' => $warnings];
    }

    /** @param  list<array{0: string, 1: string, 2: string|null}>  $warnings */
    private function seal(?string $safetyBackup, array &$warnings): bool
    {
        $lock = $this->lock->current();
        if ($safetyBackup === null || ! $lock instanceof LockRecord) {
            return false;
        }
        try {
            return $this->safety->seal($this->directory->state(), $safetyBackup, new LockHandle($lock))['encrypted'];
        } catch (Throwable $e) {
            $warnings[] = ['safety_backup_not_sealed', 'The safety backup '.$safetyBackup.' stays unencrypted (0600): '.$e->getMessage(), 'Encrypt or delete it by hand.'];

            return false;
        }
    }

    private function record(?string $backup, ?string $safetyBackup, JournalRecord $record): void
    {
        $state = $this->directory->state();
        $history = $state->history();
        $last = $history === [] ? [] : $history[array_key_last($history)];
        if (($last['event'] ?? null) === 'restored' && ($last['journal'] ?? null) === $record->id()) {
            return; // a resumed cleanup: recorded already
        }
        $finish = $record->phaseData('finish');
        $this->directory->saveState($state->withHistoryEntry('restored', [
            'backup' => $backup, 'safety_backup' => $safetyBackup, 'journal' => $record->id(),
            'epoch' => is_int($finish['epoch'] ?? null) ? $finish['epoch'] : null,
        ]));
    }
}
