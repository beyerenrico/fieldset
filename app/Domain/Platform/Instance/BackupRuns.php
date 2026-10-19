<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What `/platform` reads about backups (R39, R47, ADR 0084): the newest rows of `backup_runs`, which the fsa backup
 * service writes, and the alerts they raise (BackupAlerts). The `/platform` instance page (plan U20) shows them first;
 * until then the health page's backup check (BackupCheck) does. An instance not run by fsa has no rows.
 */
final readonly class BackupRuns
{
    public const int RECENT = 50;

    /** @return list<array{kind: string, status: string, started_at: string, finished_at: string|null, backup: string|null, bytes: int|null, encrypted: bool, reason: string|null, message: string|null, missing_files: int}> newest first */
    public function recent(int $limit = self::RECENT): array
    {
        $rows = DB::table('backup_runs')->orderByDesc('started_at')->limit($limit)->get();

        return array_values($rows->map(static fn (stdClass $row): array => [
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
        ])->all());
    }

    /** @return array{last: array<string, mixed>|null, last_success: array<string, mixed>|null, alerts: list<array{code: string, run: array<string, mixed>|null}>} */
    public function summary(?CarbonImmutable $now = null): array
    {
        $runs = $this->recent();

        return [
            'last' => $runs[0] ?? null,
            'last_success' => array_find($runs, static fn (array $run): bool => $run['status'] === 'succeeded'),
            'alerts' => BackupAlerts::evaluate($runs, $now ?? CarbonImmutable::now('UTC')),
        ];
    }
}
