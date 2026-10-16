<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Tasks;

use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Workflows\Engine\WorkflowRunner;
use App\Domain\Workflows\Enums\TaskStatus;
use App\Domain\Workflows\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Closes review tasks a submission no longer needs (ADR 0032): when it left `in_review` without that task
 * being decided, its open tasks become `cancelled` (completing one answers 409 `task_closed`) and the runs
 * waiting for them end as `cancelled`. Runs inside the tenant.
 */
final readonly class CancelOpenReviewTasks
{
    public function __construct(private WorkflowRunner $runner) {}

    /** @return int the number of cancelled tasks */
    public function handle(string $submissionUuid, SubmissionStatus $status): int
    {
        return DB::transaction(function () use ($submissionUuid, $status): int {
            $tasks = Task::query()->where('submission_uuid', $submissionUuid)->where('status', TaskStatus::Open->value)
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($tasks as $task) {
                $this->cancel($task, $status);
            }

            return $tasks->count();
        });
    }

    /** Cancels one task and ends the run waiting for it. Callers hold a transaction and the task's lock. */
    public function cancel(Task $task, SubmissionStatus $status): void
    {
        $task->forceFill(['status' => TaskStatus::Cancelled])->save();
        if ($task->workflow_run_id !== null) {
            $this->runner->cancel($task->workflow_run_id, $task->workflow_step_run_id, __('workflows.log.task_cancelled', ['status' => $status->label()]));
        }
    }
}
