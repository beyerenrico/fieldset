<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Actions\SubmissionPresenter;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Enums\TaskOutcome;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Tasks\CompleteReviewTask;
use App\Domain\Workflows\Tasks\TaskAccess;
use App\Domain\Workflows\Tasks\TaskPresenter;
use App\Http\Api\ApiException;
use App\Http\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Review tasks (M4, docs/api/field-app-v1.md): `GET /tasks` lists tasks assigned to the user or their
 * groups that they may complete; `POST /tasks/{id}/complete` (behind IdempotentRequest) approves or
 * rejects. Order of checks: not assigned (or open but not actionable) → 404, invalid body → 422, completed or
 * cancelled → 409 `task_closed`, run failed → 409 `review_blocked`.
 */
final readonly class TaskController
{
    public function __construct(private TaskAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['open', 'completed'])]]);
        $status = $request->filled('status') ? (string) $request->query('status') : 'open';
        $page = $this->access->actionable(Task::query(), $this->user($request))->where('status', $status)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(50);

        return ApiResponse::ok(
            TaskPresenter::presentMany($page->items()),
            ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        );
    }

    public function complete(Request $request, string $id, CompleteReviewTask $complete): JsonResponse
    {
        $user = $this->user($request);
        $task = ctype_digit($id) && strlen($id) < 19 ? $this->access->assigned(Task::query()->whereKey((int) $id), $user)->first() : null;
        if (! $task instanceof Task || ($task->isOpen() && ! $this->access->canComplete($user, $task))) {
            throw new ApiException('not_found', __('api.not_found'), 404);
        }
        $request->validate([
            'outcome' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'comment' => ['nullable', 'string', 'max:'.CompleteReviewTask::COMMENT_MAX, 'required_if:outcome,reject'],
        ]);

        $done = $complete->handle($task, $user, TaskOutcome::fromVerb((string) $request->input('outcome')), $request->input('comment'));
        $submission = Submission::query()->findOrFail($done->submission_uuid);

        return ApiResponse::ok(['task' => TaskPresenter::present($done->refresh()), 'submission' => SubmissionPresenter::present($submission, $user)]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
