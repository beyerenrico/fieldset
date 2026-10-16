<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Engine;

use App\Domain\Submissions\Actions\TransitionSubmission;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;

/**
 * The automatic lifecycle edges of §6.5 (ADR 0032), always through TransitionSubmission:
 *  - `submitted → completed` when no run started by the submit requests a review or sets the status
 *    (`workflows.complete_without_review`, off in the test environment);
 *  - `approved → completed` when the run that manages the status finished its remaining steps.
 * Callers hold a transaction.
 */
final readonly class LifecycleRules
{
    public function __construct(private TransitionSubmission $transition) {}

    public function afterSubmitted(string $submissionUuid, string $eventId): void
    {
        if (! (bool) config('workflows.complete_without_review', true)) {
            return;
        }
        $managed = WorkflowRun::query()->where('submission_uuid', $submissionUuid)->where('event_id', $eventId)->get()
            ->contains(fn (WorkflowRun $run): bool => Workflow::snapshotManagesStatus($run->definition_snapshot));
        if ($managed) {
            return;
        }

        $this->complete($submissionUuid, SubmissionStatus::Submitted);
    }

    public function afterRunCompleted(WorkflowRun $run): void
    {
        if ($run->submission_uuid !== null && Workflow::snapshotManagesStatus($run->definition_snapshot)) {
            $this->complete($run->submission_uuid, SubmissionStatus::Approved);
        }
    }

    private function complete(string $submissionUuid, SubmissionStatus $expected): void
    {
        $submission = Submission::query()->whereKey($submissionUuid)->lockForUpdate()->first();
        if ($submission instanceof Submission && $submission->status === $expected) {
            $this->transition->handle($submission, SubmissionStatus::Completed, null, null, 'submission.completed');
        }
    }
}
