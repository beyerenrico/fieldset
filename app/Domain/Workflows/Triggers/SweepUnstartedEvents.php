<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Triggers;

use App\Domain\Submissions\Models\SubmissionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Safety net of the queued trigger start (ADR 0032, M4 review C3): a status change whose workflows were not
 * started within `workflows.start_sweep_after_minutes` (lost dispatch, job failed for good) gets its
 * StartWorkflowsForEvent queued again, so runs and the automatic `submitted → completed` are not lost.
 * Only the last `workflows.start_sweep_days` days are scanned; the job is idempotent.
 */
final readonly class SweepUnstartedEvents
{
    public const int BATCH = 100;

    public function handle(CarbonImmutable $now): int
    {
        $after = max(1, (int) config('workflows.start_sweep_after_minutes', 10));
        $rows = SubmissionEvent::query()->withoutTenant()
            ->whereNull('workflows_processed_at')->whereNotNull('from_status')
            ->where('created_at', '<', $now->subMinutes($after))
            ->where('created_at', '>=', $now->subDays(max(1, (int) config('workflows.start_sweep_days', 7))))
            ->orderBy('id')->limit(self::BATCH)->get();

        foreach ($rows as $row) {
            try {
                Bus::dispatch(StartWorkflowsForEvent::fromRow($row));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $rows->count();
    }
}
