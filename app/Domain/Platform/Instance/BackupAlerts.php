<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

/**
 * When backup runs need an operator (R39, plan U16, ADR 0084). One rule set for `fsa status` (the run ledger in
 * `backups/runs.log`) and `/platform` (the `backup_runs` table):
 *
 * - `backup_failed`: the newest backup run that was not skipped failed (until a later run succeeds);
 * - `backup_disk_space`: the newest scheduled run since the last success was skipped for lack of disk space (at once);
 * - `backup_skipped`: a scheduled run was skipped because an operation held the instance lock more than 24 hours ago,
 *   and no run succeeded since: skips alert like failures once they last;
 * - `backup_service_failed`: the backup service's newest start failed (its configuration);
 * - `backup_overdue` (with a schedule that is on): two hours after the first slot following the newest success, or,
 *   without one, following `$since` (scheduledSince: when the service first started with the schedule on; restarts do
 *   not move it), no run has succeeded: the service is not running, or cannot complete a backup. A schedule switched
 *   on later, or an upgraded instance, therefore gets its first slot before it is overdue.
 *
 * A run is an array with `kind` (scheduled, manual, safety-…, or `service` for the service's own start), `status`
 * (succeeded, failed, skipped, started), `started_at` (ISO 8601), and optionally `reason`, `message` and `backup`; the
 * list is newest first.
 */
final readonly class BackupAlerts
{
    public const int SKIP_ALERT_HOURS = 24;

    /** How long after a missed slot a backup counts as overdue: a long run, a restart around the slot. */
    public const int OVERDUE_GRACE_HOURS = 2;

    public const string SERVICE = 'service';

    /**
     * @param  list<array<string, mixed>>  $runs  newest first
     * @param  array{time: string|null, zone: string}|null  $schedule  the daily time (`HH:MM`, `off` or null) and zone
     * @return list<array{code: string, run: array<string, mixed>|null}>
     */
    public static function evaluate(array $runs, CarbonImmutable $now, ?array $schedule = null, ?CarbonImmutable $since = null): array
    {
        $jobs = self::jobs($runs);
        $success = array_find($jobs, static fn (array $run): bool => ($run['status'] ?? null) === 'succeeded');
        $successAt = self::time($success);
        $after = array_values(array_filter($jobs, static fn (array $run): bool => ! $successAt instanceof CarbonImmutable || (self::time($run)?->greaterThan($successAt) ?? false)));
        $alerts = [];
        $attempt = array_find($jobs, static fn (array $run): bool => ($run['status'] ?? null) !== 'skipped');
        if (($attempt['status'] ?? null) === 'failed') {
            $alerts[] = ['code' => 'backup_failed', 'run' => $attempt];
        }
        $scheduled = array_find($after, static fn (array $run): bool => ($run['kind'] ?? null) === 'scheduled');
        if (($scheduled['status'] ?? null) === 'skipped' && ($scheduled['reason'] ?? null) === 'low_disk_space') {
            $alerts[] = ['code' => 'backup_disk_space', 'run' => $scheduled];
        }
        $staleSkip = array_find(array_reverse($after), static fn (array $run): bool => self::isLockSkip($run) && (self::time($run)?->lessThan($now->subHours(self::SKIP_ALERT_HOURS)) ?? false));
        if ($staleSkip !== null) {
            $alerts[] = ['code' => 'backup_skipped', 'run' => $staleSkip];
        }
        $service = self::service($runs);
        if (($service['status'] ?? null) === 'failed') {
            $alerts[] = ['code' => 'backup_service_failed', 'run' => $service];
        }
        $due = self::due($schedule, $successAt ?? $since);
        if ($due instanceof CarbonImmutable && $now->greaterThan($due->addHours(self::OVERDUE_GRACE_HOURS))) {
            $alerts[] = ['code' => 'backup_overdue', 'run' => $success];
        }

        return $alerts;
    }

    /**
     * The first slot after `$reference`, when the schedule is on and the reference known.
     *
     * @param  array{time: string|null, zone: string}|null  $schedule
     */
    public static function due(?array $schedule, ?CarbonImmutable $reference): ?CarbonImmutable
    {
        $time = $schedule['time'] ?? null;
        $zone = $schedule['zone'] ?? 'UTC';
        if (! $reference instanceof CarbonImmutable || ! is_string($time) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time) !== 1 || ! in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            return null;
        }

        return BackupSlot::after($time, new DateTimeZone($zone), $reference);
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>> the backup runs, without the service's own start records
     */
    public static function jobs(array $runs): array
    {
        return array_values(array_filter($runs, static fn (array $run): bool => ($run['kind'] ?? null) !== self::SERVICE));
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, mixed>|null the newest record of the backup service's start
     */
    public static function service(array $runs): ?array
    {
        return array_find($runs, static fn (array $run): bool => ($run['kind'] ?? null) === self::SERVICE);
    }

    /**
     * Since when the backup service runs with a schedule that is on: the oldest of its starts since it last started with
     * the schedule off (or never started at all: null). Restarts do not move it, so a service restarted every day before
     * its slot still turns overdue; switching the schedule on starts it anew.
     *
     * @param  list<array<string, mixed>>  $runs  newest first
     */
    public static function scheduledSince(array $runs): ?CarbonImmutable
    {
        $since = null;
        foreach ($runs as $run) {
            if (($run['kind'] ?? null) !== self::SERVICE || ($run['status'] ?? null) !== 'started') {
                continue;
            }
            if (! is_string($run['schedule'] ?? null) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $run['schedule']) !== 1) {
                break;
            }
            $since = self::time($run) ?? $since;
        }

        return $since;
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

    /** @param  array<string, mixed>  $run */
    private static function isLockSkip(array $run): bool
    {
        return ($run['status'] ?? null) === 'skipped' && ($run['kind'] ?? null) === 'scheduled' && in_array($run['reason'] ?? null, ['lock_held', null], true);
    }
}
