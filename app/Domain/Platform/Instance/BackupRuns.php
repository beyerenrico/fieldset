<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What `/platform` reads about backups (R39, R47, ADR 0084): the newest rows of `backup_runs`, which the fieldset backup
 * service writes (its backup runs, and its own start with the schedule it runs, kind `service`), and the alerts they
 * raise (BackupAlerts), the overdue check included: the newest service start gives the schedule, and the starts since
 * the schedule is on the reference for an instance without a successful backup yet. The `/platform` instance page
 * (plan U20, InstanceOverview) shows them first, the health page's backup check (BackupCheck) as its status, and
 * BackupAlertNotifier mails them to the operators. An instance not run by fieldset has no rows.
 */
final readonly class BackupRuns
{
    public const int RECENT = 50;

    /** @return list<array{kind: string, status: string, started_at: string, finished_at: string|null, backup: string|null, bytes: int|null, encrypted: bool, reason: string|null, message: string|null, missing_files: int, schedule: string|null, time_zone: string|null}> newest first */
    public function recent(int $limit = self::RECENT): array
    {
        return $this->rows(DB::table('backup_runs')->orderByDesc('started_at')->limit($limit)->get()->all());
    }

    /**
     * The backup service's own start records, newest first, apart from the runs: hourly skips never push them out.
     *
     * @return list<array<string, mixed>>
     */
    private function serviceStarts(): array
    {
        return $this->rows(DB::table('backup_runs')->where('kind', BackupAlerts::SERVICE)->orderByDesc('started_at')->limit(self::RECENT * 4)->get()->all());
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array{kind: string, status: string, started_at: string, finished_at: string|null, backup: string|null, bytes: int|null, encrypted: bool, reason: string|null, message: string|null, missing_files: int, schedule: string|null, time_zone: string|null}>
     */
    private function rows(array $rows): array
    {
        return array_values(array_map(static fn (stdClass $row): array => [
            'kind' => (string) $row->kind,
            'status' => (string) $row->status,
            'started_at' => CarbonImmutable::parse((string) $row->started_at)->utc()->toIso8601ZuluString(),
            'finished_at' => $row->finished_at === null ? null : CarbonImmutable::parse((string) $row->finished_at)->utc()->toIso8601ZuluString(),
            'backup' => $row->backup === null ? null : (string) $row->backup,
            'bytes' => $row->bytes === null ? null : (int) $row->bytes,
            'encrypted' => (bool) $row->encrypted,
            'reason' => $row->reason === null ? null : (string) $row->reason,
            'message' => $row->message === null ? null : (string) $row->message,
            'missing_files' => (int) $row->missing_files,
            'schedule' => $row->schedule === null ? null : (string) $row->schedule,
            'time_zone' => $row->time_zone === null ? null : (string) $row->time_zone,
        ], array_filter($rows, static fn (mixed $row): bool => $row instanceof stdClass)));
    }

    /**
     * @return array{last: array<string, mixed>|null, last_success: array<string, mixed>|null, service: array<string, mixed>|null, next_run: string|null, alerts: list<array{code: string, run: array<string, mixed>|null}>}
     */
    public function summary(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $starts = $this->serviceStarts();
        $runs = [...BackupAlerts::jobs($this->recent()), ...$starts];
        usort($runs, static fn (array $a, array $b): int => strcmp((string) $b['started_at'], (string) $a['started_at']));
        $jobs = BackupAlerts::jobs($runs);
        $service = $starts[0] ?? null;
        $success = array_find($jobs, static fn (array $run): bool => $run['status'] === 'succeeded');
        $schedule = $service === null ? null : ['time' => $service['schedule'] ?? null, 'zone' => (string) ($service['time_zone'] ?? 'UTC')];
        $since = BackupAlerts::scheduledSince($starts);

        return [
            'last' => $jobs[0] ?? null,
            'last_success' => $success,
            'service' => $service,
            'next_run' => BackupAlerts::due($schedule, $now)?->toIso8601ZuluString(),
            'alerts' => BackupAlerts::evaluate($runs, $now, $schedule, $since),
        ];
    }
}
