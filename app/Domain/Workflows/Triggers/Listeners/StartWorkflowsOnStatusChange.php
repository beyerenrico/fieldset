<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Triggers\Listeners;

use App\Domain\Submissions\Events\SubmissionStatusChanged;
use App\Domain\Workflows\Triggers\StartWorkflowsForEvent;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Submission triggers (submitted, status_changed, approved, rejected). Runs after the transition committed
 * and queues StartWorkflowsForEvent (conditions and runs are handled there, ADR 0032); a dispatch error is
 * reported, never thrown (the transition already succeeded) — SweepUnstartedEvents retries it.
 */
final readonly class StartWorkflowsOnStatusChange
{
    public function handle(SubmissionStatusChanged $event): void
    {
        try {
            Bus::dispatch(StartWorkflowsForEvent::fromEvent($event));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
