<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Tasks\Listeners;

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Events\SubmissionStatusChanged;
use App\Domain\Workflows\Tasks\CancelOpenReviewTasks;
use Throwable;

/**
 * A submission that is no longer `in_review` needs no open review task (ADR 0032, review C1): after any
 * transition to another status, its remaining open tasks are cancelled. A decided task is already completed
 * when CompleteReviewTask's transition fires, so only stale ones are affected. Errors are reported; a task
 * left open is still refused when completed (CompleteReviewTask checks the status).
 */
final readonly class CancelReviewTasksOnStatusChange
{
    public function __construct(private TenantContext $tenant, private CancelOpenReviewTasks $cancel) {}

    public function handle(SubmissionStatusChanged $event): void
    {
        $to = SubmissionStatus::tryFrom($event->toStatus);
        if (! $to instanceof SubmissionStatus || $to === SubmissionStatus::InReview) {
            return;
        }
        try {
            $this->tenant->run($event->organizationId, fn (): int => $this->cancel->handle($event->submissionUuid, $to));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
