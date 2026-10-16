<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Tasks;

use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Actions\TransitionSubmission;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Engine\WorkflowRunner;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Enums\TaskOutcome;
use App\Domain\Workflows\Enums\TaskStatus;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Support\WorkflowNotifications;
use App\Http\Api\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approve or reject a review task (§8.3), shared by the API, "My tasks" and the inbox. Approve: the
 * submission becomes `approved` and the run continues (SubmissionApproved to the submitter). Reject
 * (comment required): `changes_requested` with the comment on the submission event, the run ends as
 * `rejected` and the submitter is notified (ChangesRequested); a resubmit starts a new run. A task is
 * completed once (409 `task_closed`). Authorization is the caller's job (TaskAccess).
 */
final readonly class CompleteReviewTask
{
    public const int COMMENT_MAX = 2000;

    public function __construct(
        private TransitionSubmission $transition,
        private WorkflowRunner $runner,
        private WorkflowNotifications $notifications,
    ) {}

    public function handle(Task $task, User $actor, TaskOutcome $outcome, ?string $comment): Task
    {
        $comment = $this->comment($outcome, $comment);

        return DB::transaction(function () use ($task, $actor, $outcome, $comment): Task {
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new ApiException('task_closed', __('workflows.tasks.errors.closed'), 409);
            }
            $submission = Submission::query()->whereKey($locked->submission_uuid)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'status' => TaskStatus::Completed, 'outcome' => $outcome, 'comment' => $comment,
                'completed_by' => $actor->id, 'completed_at' => now(),
            ])->save();
            $run = $locked->workflow_run_id === null ? null : WorkflowRun::query()->find($locked->workflow_run_id);
            $output = ['task_id' => $locked->id, 'outcome' => $outcome->value, 'completed_by' => $actor->id];

            if ($outcome === TaskOutcome::Approved) {
                $this->transition->handle($submission, SubmissionStatus::Approved, $actor, $comment, 'submission.approved');
                if ($run instanceof WorkflowRun) {
                    $this->runner->resume($run, $output, __('workflows.log.approved', ['name' => $actor->name]));
                }
                DB::afterCommit(fn () => $this->notifications->approved($submission));
            } else {
                $this->transition->handle($submission, SubmissionStatus::ChangesRequested, $actor, $comment, 'submission.rejected');
                if ($run instanceof WorkflowRun) {
                    $this->runner->end($run, RunStatus::Rejected, $output, __('workflows.log.rejected', ['name' => $actor->name]));
                }
                DB::afterCommit(fn () => $this->notifications->changesRequested($submission, (string) $comment));
            }

            return $locked;
        });
    }

    /** Trimmed comment; required (and limited) for a rejection. */
    private function comment(TaskOutcome $outcome, ?string $comment): ?string
    {
        $comment = trim((string) $comment);
        if ($outcome === TaskOutcome::Rejected && $comment === '') {
            throw ValidationException::withMessages(['comment' => __('workflows.tasks.errors.comment_required')]);
        }
        if (mb_strlen($comment) > self::COMMENT_MAX) {
            throw ValidationException::withMessages(['comment' => __('validation.max.string', ['attribute' => 'comment', 'max' => self::COMMENT_MAX])]);
        }

        return $comment === '' ? null : $comment;
    }
}
