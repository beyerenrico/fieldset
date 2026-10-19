<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * When backup runs need an operator (R39, plan U16, ADR 0084). One rule set for `fsa status` (the run ledger in
 * `backups/runs.log`) and `/platform` (the `backup_runs` table):
 *
 * - `backup_failed`: the newest run that was not skipped failed (until a later run succeeds);
 * - `backup_skipped`: a scheduled run was skipped (an operation held the instance lock) more than 24 hours ago and no
 *   run succeeded since, so skips alert like failures once they last;
 * - `backup_overdue` (only when the caller knows the schedule is on): no run succeeded within 26 hours of the newest
 *   success, or of `$since` (the install) when there was none: the backup service is not running at all.
 *
 * A run is an array with `kind`, `status` (succeeded, failed, skipped), `started_at` (ISO 8601), and optionally
 * `reason`, `message` and `backup`; the list is newest first.
 */
final readonly class BackupAlerts
{
    public const int SKIP_ALERT_HOURS = 24;

    /** A daily schedule plus two hours of slack (a long run, a restart around the slot). */
    public const int OVERDUE_HOURS = 26;

    /**
     * @param  list<array<string, mixed>>  $runs  newest first
     * @return list<array{code: string, run: array<string, mixed>|null}>
     */
    public static function evaluate(array $runs, CarbonImmutable $now, bool $scheduled = false, ?CarbonImmutable $since = null): array
    {
        $alerts = [];
        $attempt = array_find($runs, static fn (array $run): bool => ($run['status'] ?? null) !== 'skipped');
        if (($attempt['status'] ?? null) === 'failed') {
            $alerts[] = ['code' => 'backup_failed', 'run' => $attempt];
        }
        $success = array_find($runs, static fn (array $run): bool => ($run['status'] ?? null) === 'succeeded');
        $successAt = self::time($success);
        $staleSkip = array_find(array_reverse($runs), static function (array $run) use ($successAt, $now): bool {
            $at = self::time($run);

            return ($run['status'] ?? null) === 'skipped' && ($run['kind'] ?? null) === 'scheduled' && $at instanceof CarbonImmutable
                && (! $successAt instanceof CarbonImmutable || $at->greaterThan($successAt)) && $at->lessThan($now->subHours(self::SKIP_ALERT_HOURS));
        });
        if ($staleSkip !== null) {
            $alerts[] = ['code' => 'backup_skipped', 'run' => $staleSkip];
        }
        $reference = $successAt ?? $since;
        if ($scheduled && $reference instanceof CarbonImmutable && $reference->lessThan($now->subHours(self::OVERDUE_HOURS))) {
            $alerts[] = ['code' => 'backup_overdue', 'run' => $success];
        }

        return $alerts;
    }

    /** @param  array<string, mixed>|null  $run */
    public static function time(?array $run): ?CarbonImmutable
    {
        $value = $run['started_at'] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
