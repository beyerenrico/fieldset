<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

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
 * the alerts (BackupAlerts) as `warn`: a failed run, scheduled runs skipped for more than 24 hours, no success for 26.
 */
final readonly class BackupStatus
{
    private const array RUN_FIELDS = ['kind', 'status', 'started_at', 'finished_at', 'backup', 'bytes', 'encrypted', 'reason', 'message', 'missing_files', 'recorded'];

    public function __construct(private InstanceDirectory $directory) {}

    /** @return array{data: array<string, mixed>, warnings: list<array{0: string, 1: string, 2: string}>} */
    public function of(InstanceState $state, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $settings = InstanceSettings::of($state);
        $runs = new BackupRunLog($this->directory)->recent();
        $entries = new BackupStore($this->directory)->entries();
        $schedule = $this->schedule($settings);
        $installed = $this->installedAt($state);
        $success = array_find($runs, static fn (array $run): bool => $run['status'] === 'succeeded');
        $alerts = BackupAlerts::evaluate($runs, $now, $schedule?->enabled() ?? false, $installed);

        return ['data' => [
            'last' => isset($runs[0]) ? $this->run($runs[0]) : null,
            'last_success' => $success === null ? null : $this->run($success),
            'schedule' => [
                'time' => $settings->backupSchedule(), 'time_zone' => $settings->timeZone(), 'retention' => $settings->backupRetention(),
                'next_run' => $schedule?->nextRun($now, $this->lastScheduled($runs), $now)?->toIso8601ZuluString(),
            ],
            'encrypted' => $state->recipients() !== [],
            'directory' => $this->directory->root.'/'.InstanceDirectory::BACKUPS_DIR,
            'backups' => ['count' => count($entries), 'bytes' => array_sum(array_map(static fn (BackupEntry $entry): int => $entry->bytes, $entries)), 'newest' => isset($entries[0]) ? $entries[0]->toArray() : null],
            'safety' => array_values(array_map(static fn (BackupEntry $entry): array => [...$entry->toArray(), 'age_hours' => (int) floor($entry->name->at->diffInHours($now, true))], array_filter($entries, static fn (BackupEntry $entry): bool => $entry->name->isSafety()))),
            'alerts' => array_column($alerts, 'code'),
        ], 'warnings' => array_map(static fn (array $alert): array => self::warning($alert['code'], $alert['run']), $alerts)];
    }

    /**
     * @param  array<string, mixed>|null  $run
     * @return array{0: string, 1: string, 2: string}
     */
    private static function warning(string $code, ?array $run): array
    {
        return match ($code) {
            'backup_failed' => [$code, 'The last backup failed ('.($run['reason'] ?? 'unknown').'): '.($run['message'] ?? 'no message').'.', 'Read `fsa logs backup`, fix the cause, then run `fsa backup`.'],
            'backup_skipped' => [$code, 'Scheduled backups have been skipped for more than 24 hours because an operation held the instance lock.', 'Finish the unfinished operation (`fsa status` names it), then run `fsa backup`.'],
            default => [$code, 'No backup succeeded for more than '.BackupAlerts::OVERDUE_HOURS.' hours: the backup service may not be running.', 'Check `fsa logs backup` and `fsa doctor --post`, then run `fsa backup`.'],
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
