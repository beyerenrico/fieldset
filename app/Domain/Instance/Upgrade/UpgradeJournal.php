<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Restore\RestoreNames;
use App\Domain\Instance\State\InstanceState;

/**
 * The upgrade journal (plan "Upgrade journal", KTD19, R54, ADR 0086): its phases in order, each written before its step
 * starts, and the decisions a resumed run takes from it. The KTD19 steps map to `release` (1: the verified target and
 * its path), `doctor` (1: checks and space), `pull` (2), `quiesce` (3, 4), `safety_backup` (5), `migrate` (6: the child),
 * `start` (7), `smoke` (8), `commit` (9) and `open` (10); `revert` brings the previous services back before step 5, and
 * the `rollback_*` phases restore the safety backup from step 5 until the commit.
 */
final class UpgradeJournal
{
    public const string OPERATION = 'upgrade';

    public const array PHASES = ['release', 'doctor', 'pull', 'quiesce', 'safety_backup', 'migrate', 'start', 'smoke', 'commit', 'open'];

    public const array ROLLBACK_PHASES = [
        'rollback_stop', 'rollback_ledger', 'rollback_prepare', 'rollback_database', 'rollback_storage', 'rollback_finish',
        'rollback_swap', 'rollback_verify', 'rollback_open', 'rollback_cleanup',
    ];

    /**
     * The upgrade committed: its `commit` phase completed, or started while the state already names the target (killed
     * between writing the state and journaling the phase). Nothing is ever restored automatically from here on.
     */
    public static function committed(JournalRecord $record, InstanceState $state): bool
    {
        if ($record->hasCompleted('commit')) {
            return true;
        }
        $version = $record->phaseData('release')['version'] ?? null;

        return $record->hasStarted('commit') && is_string($version) && $state->version() === $version;
    }

    /** A rollback began (an earlier run's, after an exit 8 or a kill): a resumed run only ever finishes it. */
    public static function rollingBack(JournalRecord $record): bool
    {
        return $record->hasStarted('rollback_stop');
    }

    /** The database may have changed: the safety backup completed and the child may have started. */
    public static function pastSafetyBackup(JournalRecord $record): bool
    {
        return $record->hasCompleted('safety_backup');
    }

    /** The names the rollback works with (journaled with `rollback_stop`, else derived from the state). */
    public static function names(JournalRecord $record, InstanceState $state): RestoreNames
    {
        $recorded = $record->phaseData('rollback_stop')['names'] ?? null;

        return is_array($recorded) ? RestoreNames::fromArray($recorded) : RestoreNames::for($state, $record->id());
    }

    /** The short hex ID of a journal, for the stage directory and the child's names. */
    /** The journal note naming a safety backup the moment the backup service wrote it, before its verification. */
    public const string SAFETY_NOTED = 'safety_backup_taken';

    /**
     * The safety backups a run made: the phase's and every noted one.
     *
     * @return list<string>
     */
    public static function safetyBackups(JournalRecord $record, string $phase = 'safety_backup'): array
    {
        $noted = array_map(static fn (array $event): mixed => $event['data']['backup'] ?? null, array_filter($record->events(), static fn (array $event): bool => $event['type'] === self::SAFETY_NOTED));

        return array_values(array_unique(array_filter([$record->phaseData($phase)['backup'] ?? null, ...$noted], is_string(...))));
    }

    /** The snapshot of the live release files step 7 replaces (ReleaseFiles). */
    public static function filesBefore(string $id): string
    {
        return 'files-before-upgrade-'.$id;
    }

    public static function shortId(JournalRecord $record): string
    {
        return RestoreNames::id($record->id());
    }

    /** @return array<string, string> a journaled map of strings (image role => reference) */
    public static function strings(mixed $values): array
    {
        return is_array($values) ? array_filter(array_map(static fn (mixed $value): ?string => is_string($value) ? $value : null, $values), is_string(...)) : [];
    }

    /** @return list<string> a journaled list of strings */
    public static function list(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
