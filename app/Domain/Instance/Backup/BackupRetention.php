<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

/**
 * Which backups a successful scheduled run may prune, and whether a run fits on the disk (E13, R57, plan U16,
 * ADR 0084). Pure decisions; BackupRun applies them.
 *
 * - Only scheduled backups are pruned, the oldest beyond the newest `keep`; never a safety, manual or `final-…`
 *   backup, never one marked kept, never the newest complete backup of any kind, never one held (held()) while the
 *   newest run misses referenced files it may still hold (M12 gate D5).
 * - A run is skipped (scheduled) or refused (manual, safety) when the free space after its projected size would drop
 *   below the threshold: the projection is the newest backup's size plus a quarter, as databases grow, or, without any
 *   backup, the size of the database and the storage volume; an encrypted run needs the plain set and its age file side
 *   by side for a moment, so twice that.
 */
final readonly class BackupRetention
{
    public const int DEFAULT_KEEP = 7;

    public const int MAX_KEEP = 365;

    /** Free space a run must leave behind (MiB), unless BACKUP_MIN_FREE_MIB says otherwise. */
    public const int DEFAULT_MIN_FREE_MIB = 1024;

    /**
     * @param  list<BackupEntry>  $entries  complete backups
     * @param  list<string>  $hold  names never pruned (held())
     * @return list<BackupEntry> the scheduled backups to prune, oldest first
     */
    public static function prune(array $entries, int $keep, array $hold = []): array
    {
        $keep = max(1, $keep);
        usort($entries, static fn (BackupEntry $a, BackupEntry $b): int => $b->name->at <=> $a->name->at);
        $newest = $entries[0] ?? null;
        $scheduled = array_values(array_filter($entries, static fn (BackupEntry $entry): bool => $entry->name->isScheduled()));
        $prune = array_filter(
            array_slice($scheduled, $keep),
            static fn (BackupEntry $entry): bool => ! $entry->kept && $entry !== $newest && ! in_array($entry->name->name(), $hold, true),
        );

        return array_reverse(array_values($prune));
    }

    /**
     * The scheduled backups that may hold referenced files the newest one misses (M12 gate D5): every other one that
     * missed fewer (its count from the run ledger) or an unknown number (no ledger line says), newest first. Nothing
     * while the newest misses none; the hold lasts as long as the files stay missing, so retention never costs the
     * backups that still hold them. Counts stand in for the files themselves: one that missed as many is not held.
     *
     * @param  list<BackupEntry>  $entries  complete backups
     * @param  array<string, int|null>  $missing  backup name => the missing_files its run recorded
     * @return list<string>
     */
    public static function held(array $entries, string $newest, int $newestMissing, array $missing): array
    {
        if ($newestMissing === 0) {
            return [];
        }
        usort($entries, static fn (BackupEntry $a, BackupEntry $b): int => $b->name->at <=> $a->name->at);
        $held = array_filter($entries, static fn (BackupEntry $entry): bool => $entry->name->isScheduled() && $entry->name->name() !== $newest
            && (($missing[$entry->name->name()] ?? null) === null || $missing[$entry->name->name()] < $newestMissing));

        return array_values(array_map(static fn (BackupEntry $entry): string => $entry->name->name(), $held));
    }

    /**
     * @param  list<BackupEntry>  $entries
     * @param  int  $estimate  without any backup: the size of the database and the storage volume (BackupWriter)
     */
    public static function projectedBytes(array $entries, bool $encrypted = false, int $estimate = 0): int
    {
        $newest = null;
        foreach ($entries as $entry) {
            $newest = $newest === null || $entry->name->at > $newest->name->at ? $entry : $newest;
        }

        return (int) ceil(($newest === null ? $estimate : $newest->bytes) * 1.25 * ($encrypted ? 2 : 1));
    }

    /** Whether a run of the projected size leaves at least the threshold free. */
    public static function fits(int $freeBytes, int $projectedBytes, int $minFreeMib = self::DEFAULT_MIN_FREE_MIB): bool
    {
        return $freeBytes - $projectedBytes >= $minFreeMib * 1024 * 1024;
    }
}
