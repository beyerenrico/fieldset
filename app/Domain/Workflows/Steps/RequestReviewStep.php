<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Steps;

use App\Domain\Identity\Models\Group;
use App\Domain\Submissions\Actions\TransitionSubmission;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Engine\StepContext;
use App\Domain\Workflows\Engine\StepFailedException;
use App\Domain\Workflows\Engine\StepResult;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Support\WorkflowNotifications;
use App\Domain\Workflows\Support\WorkflowRecipients;
use Illuminate\Support\Facades\DB;

/**
 * `request_review`: creates a review task for a user or a group (`assignee_type`, `assignee_user_id`,
 * `assignee_group_id`) due after `sla_hours`, moves the submission `submitted → in_review`, notifies the
 * assignees (ReviewAssigned) and pauses the run until the task is completed (CompleteReviewTask).
 * One task per step run: a retried step finds the task it created.
 */
final readonly class RequestReviewStep implements StepHandler
{
    public function __construct(
        private TransitionSubmission $transition,
        private WorkflowNotifications $notifications,
        private WorkflowRecipients $recipients,
    ) {}

    public function execute(StepContext $context): StepResult
    {
        $uuid = $context->requireSubmission()->uuid;
        [$userId, $groupId, $assignee] = $this->assignee($context);
        $sla = $context->int('sla_hours');

        $task = DB::transaction(function () use ($context, $uuid, $userId, $groupId, $sla): Task {
            $submission = Submission::query()->whereKey($uuid)->lockForUpdate()->firstOrFail();
            $existing = Task::query()->where('workflow_step_run_id', $context->step->id)->first();
            if ($existing instanceof Task) {
                return $existing;
            }
            if ($submission->status !== SubmissionStatus::Submitted) {
                throw new StepFailedException(__('workflows.errors.not_reviewable', ['status' => $submission->status->label()]));
            }
            $task = Task::query()->create([
                'submission_uuid' => $uuid, 'workflow_run_id' => $context->run->id, 'workflow_step_run_id' => $context->step->id,
                'assignee_user_id' => $userId, 'assignee_group_id' => $groupId,
                'due_at' => $sla !== null && $sla > 0 ? now()->addHours(min($sla, (int) config('workflows.review.max_sla_hours'))) : null,
            ]);
            $this->transition->handle($submission, SubmissionStatus::InReview, null, null, 'submission.review_requested');
            DB::afterCommit(fn () => $this->notifications->reviewAssigned($task, $submission));

            return $task;
        });

        return StepResult::waiting(__('workflows.log.review_requested', ['assignee' => $assignee]), ['task_id' => $task->id]);
    }

    /** @return array{0: int|null, 1: int|null, 2: string} user id, group id, display name */
    private function assignee(StepContext $context): array
    {
        if (($context->string('assignee_type') ?? 'group') === 'user') {
            $id = $context->int('assignee_user_id');
            $user = $id === null ? null : $this->recipients->users([$id])->first();

            return $user === null
                ? throw new StepFailedException(__('workflows.errors.assignee_missing'))
                : [$user->id, null, $user->name];
        }
        $id = $context->int('assignee_group_id');
        $group = $id === null ? null : Group::query()->find($id);

        return $group instanceof Group
            ? [null, $group->id, $group->name]
            : throw new StepFailedException(__('workflows.errors.assignee_missing'));
    }
}
