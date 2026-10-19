<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Backup\BackupEntry;
use App\Domain\Instance\Backup\BackupName;
use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\Backup\SafetyBackup;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Operations\LockRecord;
use App\Domain\Instance\State\InstanceDirectory;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Safety backups an upgrade or a rollback leaves behind (R57, ADR 0084, plan U18, ADR 0086, review U18 S1). They are
 * written unencrypted (a rollback must read them without an identity) and sealed to the recipients once the operation
 * closes: after a commit, after a rollback, and on every way back (revert, abort, return), as a restore seals its own
 * (RestoreRecovery::sealed). Only an exit-8 halt keeps them plain, on purpose: the recovery needs them. A run killed
 * after the backup service wrote the set but before the journal named it is covered by `since()`, which finds the
 * plaintext sets of that kind made since the journal began. Removing superseded ones: `superseded()`.
 */
final readonly class SafetySeal
{
    public function __construct(
        private InstanceDirectory $directory,
        private BackupStore $store,
        private SafetyBackup $safety,
        private InstanceLock $lock,
    ) {}

    /**
     * Seals every named set that exists unencrypted; nothing happens without recipients (SafetyBackup::seal).
     *
     * @param  list<string|null>  $backups
     * @return list<array{0: string, 1: string, 2: string|null}> warnings for sets that stay plain
     */
    public function seal(array $backups): array
    {
        $lock = $this->lock->current();
        if (! $lock instanceof LockRecord) {
            return [];
        }
        $warnings = [];
        foreach (array_unique(array_filter($backups, is_string(...))) as $backup) {
            $entry = $this->store->find($backup);
            if (! $entry instanceof BackupEntry || $entry->encrypted) {
                continue;
            }
            try {
                $this->safety->seal($this->directory->state(), $backup, new LockHandle($lock));
            } catch (Throwable $e) {
                $warnings[] = ['safety_backup_not_sealed', "The safety backup {$backup} stays unencrypted (0600): ".$e->getMessage(), 'Encrypt or delete it by hand.'];
            }
        }

        return $warnings;
    }

    /**
     * The unencrypted safety backups of an operation made since `$startedAt` (a journal's start, to the second).
     *
     * @return list<string>
     */
    public function since(string $operation, string $startedAt): array
    {
        $from = CarbonImmutable::parse($startedAt)->startOfSecond();
        $entries = array_filter($this->store->entries(), static fn (BackupEntry $entry): bool => $entry->name->kind === BackupName::SAFETY.'-'.$operation
            && ! $entry->encrypted && $entry->name->at->greaterThanOrEqualTo($from));

        return array_values(array_map(static fn (BackupEntry $entry): string => $entry->name->name(), $entries));
    }

    /**
     * Removes the safety backups of `$operation` other than those kept (R57: they replace each other only once an
     * operation completed).
     *
     * @param  list<string|null>  $keep
     * @param  list<array{0: string, 1: string, 2: string|null}>  $warnings
     * @return list<string> the removed sets
     */
    public function removeSuperseded(string $operation, array $keep, array &$warnings): array
    {
        $removed = [];
        foreach ($this->store->entries() as $entry) {
            if ($entry->name->kind !== BackupName::SAFETY.'-'.$operation || in_array($entry->name->name(), $keep, true)) {
                continue;
            }
            if ($this->store->delete($entry)) {
                $removed[] = $entry->name->name();
            } else {
                $warnings[] = ['safety_backup_not_removed', 'The superseded safety backup '.$entry->name->name().' could not be removed.', 'Delete it by hand.'];
            }
        }

        return $removed;
    }
}
