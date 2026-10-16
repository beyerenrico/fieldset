<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Engine;

use App\Domain\Workflows\Models\WorkflowRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Queues a step after the surrounding transaction commits (submit runs inside the idempotency
 * transaction, LEARNINGS #28). A dispatch error is reported, never thrown: the triggering action
 * already committed, and `workflows:tick` re-dispatches runs that stopped making progress. (With the
 * sync queue a failing step has already recorded its own failure when the error surfaces here.)
 */
final class StepDispatcher
{
    public static function dispatch(WorkflowRun $run, int $index): void
    {
        $runId = $run->id;
        $organizationId = $run->organization_id;

        DB::afterCommit(static function () use ($runId, $index, $organizationId): void {
            try {
                Bus::dispatch(new RunWorkflowStep($runId, $index, $organizationId));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
