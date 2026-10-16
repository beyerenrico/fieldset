<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Engine;

use App\Domain\Submissions\Actions\TransitionSubmission;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Models\SubmissionEvent;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Enums\TaskOutcome;
use App\Domain\Workflows\Enums\TaskStatus;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Triggers\TriggerEvent;

/**
 * The automatic lifecycle edges of §6.5 (ADR 0032), always through TransitionSubmission:
 *  - `submitted → completed` when no run started by the (latest) submit requests a review or sets the
 *    status (`workflows.complete_without_review`, off in the test environment);
 *  - `approved → completed` ("post-approval steps done") once the approval event has been processed, every
 *    run it started (workflows triggered by submission.approved or a status change to approved) has
 *    completed, and the run that resumed after the approval has completed. A failed one keeps the
 *    submission `approved` until a manual retry succeeds.
 * Callers hold a transaction.
 */
final readonly class LifecycleRules
{
    public function __construct(private TransitionSubmission $transition) {}

    /** After the workflows of a status change were started (StartWorkflows). */
    public function afterEvent(string $submissionUuid, TriggerEvent $event): void
    {
        if ($event->toStatus === SubmissionStatus::Submitted) {
            $this->afterSubmitted($submissionUuid, $event);
        }
        if ($event->toStatus === SubmissionStatus::Approved) {
            $this->settleApproved($submissionUuid);
        }
    }

    public function afterRunCompleted(WorkflowRun $run): void
    {
        if ($run->submission_uuid !== null) {
            $this->settleApproved($run->submission_uuid);
        }
    }

    private function afterSubmitted(string $submissionUuid, TriggerEvent $event): void
    {
        if (! (bool) config('workflows.complete_without_review', true) || ! $this->isLatestEvent($submissionUuid, $event->submissionEventId)) {
            return;
        }
        $managed = WorkflowRun::query()->where('submission_uuid', $submissionUuid)->where('event_id', $event->eventId)->get()
            ->contains(fn (WorkflowRun $run): bool => Workflow::snapshotManagesStatus($run->definition_snapshot));
        if ($managed) {
            return;
        }

        $this->complete($submissionUuid, SubmissionStatus::Submitted);
    }

    private function settleApproved(string $submissionUuid): void
    {
        if (Submission::query()->whereKey($submissionUuid)->first(['uuid', 'status'])?->status !== SubmissionStatus::Approved) {
            return;
        }
        $approval = SubmissionEvent::query()->where('submission_uuid', $submissionUuid)->where('to_status', SubmissionStatus::Approved->value)
            ->orderByDesc('id')->first();
        if (! $approval instanceof SubmissionEvent || $approval->workflows_processed_at === null) {
            return;   // the approval's own workflows have not been started yet
        }
        $reviewRunId = Task::query()->where('submission_uuid', $submissionUuid)->where('status', TaskStatus::Completed->value)
            ->where('outcome', TaskOutcome::Approved->value)->orderByDesc('completed_at')->orderByDesc('id')->value('workflow_run_id');
        $pending = WorkflowRun::query()->where('submission_uuid', $submissionUuid)
            ->where(fn ($q) => $q->where('event_id', 'submission_event:'.$approval->id)->orWhere('id', $reviewRunId ?? 0))
            ->where('status', '!=', RunStatus::Completed->value)->exists();
        if (! $pending) {
            $this->complete($submissionUuid, SubmissionStatus::Approved);
        }
    }

    /** A swept old event of a submission that has moved on since does nothing. */
    private function isLatestEvent(string $submissionUuid, ?int $submissionEventId): bool
    {
        return $submissionEventId === null
            || (int) SubmissionEvent::query()->where('submission_uuid', $submissionUuid)->max('id') === $submissionEventId;
    }

    private function complete(string $submissionUuid, SubmissionStatus $expected): void
    {
        $submission = Submission::query()->whereKey($submissionUuid)->lockForUpdate()->first();
        if ($submission instanceof Submission && $submission->status === $expected) {
            $this->transition->handle($submission, SubmissionStatus::Completed, null, null, 'submission.completed');
        }
    }
}
