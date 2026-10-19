<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\Operations\LockRecord;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Platform\Instance\BackupAlerts;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * What `fsa status` reports about backups (§7, R39, R57, ADR 0084), read from the instance directory alone (the run
 * ledger and `backups/`), so it answers while Postgres is down: the last run and the last success, the schedule and its
 * next run, encryption, how many backups take how much space, the retained safety backups with their age and size, and
 * the alerts (BackupAlerts) as `warn`: a failed run, a skip for disk space, scheduled runs skipped for more than 24 hours
 * because of the lock, a service that cannot start, no success two hours after a due slot, and `.partial` directories an
 * interrupted backup left (they may hold unencrypted data).
 */
final readonly class BackupStatus
{
    private const array RUN_FIELDS = ['kind', 'status', 'started_at', 'finished_at', 'backup', 'bytes', 'encrypted', 'reason', 'message', 'missing_files', 'recorded'];

    public function __construct(private InstanceDirectory $directory) {}

    /**
     * @param  LockRecord|null  $lock  the instance lock as it is now: without one, a `.partial` directory is left over
     * @return array{data: array<string, mixed>, warnings: list<array{0: string, 1: string, 2: string}>}
     */
    public function of(InstanceState $state, ?CarbonImmutable $now = null, ?LockRecord $lock = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $settings = InstanceSettings::of($state);
        $runs = new BackupRunLog($this->directory)->recent(PHP_INT_MAX);
        $jobs = BackupAlerts::jobs($runs);
        $store = new BackupStore($this->directory);
        $entries = $store->entries();
        $schedule = $this->schedule($settings);
        $success = array_find($jobs, static fn (array $run): bool => $run['status'] === 'succeeded');
        $alerts = BackupAlerts::evaluate($runs, $now, ['time' => $schedule?->time, 'zone' => $settings->timeZone()], BackupAlerts::scheduledSince($runs) ?? $this->installedAt($state));
        $warnings = array_map(static fn (array $alert): array => self::warning($alert['code'], $alert['run']), $alerts);
        // under a lock, a backup may be being written; a staged one is the plain set of an encrypted backup (gate S3)
        $partials = $lock instanceof LockRecord ? [] : [...$store->partials(), ...array_map(static fn (string $name): string => BackupStore::STAGING.'/'.$name, $store->stagedPartials())];
        if ($partials !== []) {
            $paths = array_map(static fn (string $partial): string => str_contains($partial, '/') ? $partial : InstanceDirectory::BACKUPS_DIR.'/'.$partial, $partials);
            $warnings[] = ['backup_partial_left', 'An interrupted backup left '.implode(', ', $paths).'; it may hold unencrypted data.', 'The next backup run removes it; or delete it now. Do not copy it off the host.'];
        }

        return ['data' => [
            'last' => isset($jobs[0]) ? $this->run($jobs[0]) : null,
            'last_success' => $success === null ? null : $this->run($success),
            'schedule' => [
                'time' => $settings->backupSchedule(), 'time_zone' => $settings->timeZone(), 'retention' => $settings->backupRetention(),
                'next_run' => $schedule?->nextRun($now, $this->lastScheduled($jobs), $now)?->toIso8601ZuluString(),
            ],
            'encrypted' => $state->recipients() !== [],
            'directory' => $this->directory->root.'/'.InstanceDirectory::BACKUPS_DIR,
            'backups' => ['count' => count($entries), 'bytes' => array_sum(array_map(static fn (BackupEntry $entry): int => $entry->bytes, $entries)), 'newest' => isset($entries[0]) ? $entries[0]->toArray() : null],
            'safety' => array_values(array_map(static fn (BackupEntry $entry): array => [...$entry->toArray(), 'age_hours' => (int) floor($entry->name->at->diffInHours($now, true))], array_filter($entries, static fn (BackupEntry $entry): bool => $entry->name->isSafety()))),
            'partials' => $partials,
            'alerts' => array_column($alerts, 'code'),
        ], 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>|null  $run
     * @return array{0: string, 1: string, 2: string}
     */
    private static function warning(string $code, ?array $run): array
    {
        return match ($code) {
            'backup_failed' => [$code, 'The last backup failed ('.($run['reason'] ?? 'unknown').'): '.($run['message'] ?? 'no message').'.', 'Read `fsa logs backup`, fix the cause, then run `fsa backup`.'],
            'backup_disk_space' => [$code, 'The last scheduled backup was skipped: '.($run['message'] ?? 'not enough free disk space.'), 'Free space on the disk of the instance directory (old backups in backups/), then run `fsa backup`.'],
            'backup_skipped' => [$code, 'Scheduled backups have been skipped for more than 24 hours because an operation held the instance lock.', 'Finish the unfinished operation (`fsa status` names it), then run `fsa backup`.'],
            'backup_service_failed' => [$code, 'The backup service cannot start ('.($run['reason'] ?? 'unknown').'): '.($run['message'] ?? 'no message').'.', 'Read `fsa logs backup`; `fsa install` with the same flags renders its configuration again.'],
            default => [$code, 'No backup succeeded within '.BackupAlerts::OVERDUE_GRACE_HOURS.' hours of the scheduled slot: the backup service may not be running.', 'Check `fsa logs backup` and `fsa doctor --post`, then run `fsa backup`.'],
        };
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function run(array $run): array
    {
        return array_intersect_key($run, array_flip(self::RUN_FIELDS));
    }

    /** @param  list<array<string, mixed>>  $runs */
    private function lastScheduled(array $runs): ?CarbonImmutable
    {
        return BackupAlerts::time(array_find($runs, static fn (array $run): bool => $run['status'] === 'succeeded' && ($run['kind'] ?? null) === BackupName::SCHEDULED));
    }

    private function schedule(InstanceSettings $settings): ?BackupSchedule
    {
        try {
            return BackupSchedule::parse($settings->backupSchedule(), $settings->timeZone());
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function installedAt(InstanceState $state): ?CarbonImmutable
    {
        $entry = array_find($state->history(), static fn (array $entry): bool => ($entry['event'] ?? null) === 'installed');

        try {
            return is_string($entry['at'] ?? null) ? CarbonImmutable::parse($entry['at'])->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
