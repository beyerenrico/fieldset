<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Tasks;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Support\SubmissionVisibility;
use App\Domain\Workflows\Enums\TaskStatus;
use App\Domain\Workflows\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who may act on review tasks (§8.3, §8.6 "Review and approve"):
 *  - completing needs `submissions.review` or being the assignee user, and seeing the submission (task-based
 *    visibility needs `submissions.view_assigned` and an open task, ADR 0032: assignment alone grants nothing);
 *  - the API and "My tasks" list tasks assigned to the user or one of their groups that the user may
 *    complete (group members without `submissions.review` only see tasks assigned to them personally).
 */
final readonly class TaskAccess
{
    public function __construct(
        private OrganizationPermissions $permissions,
        private SubmissionVisibility $visibility,
        private TenantContext $tenant,
    ) {}

    public function mayReview(User $user): bool
    {
        $organizationId = $this->tenant->id();

        return $organizationId !== null && $user->isActive() && $this->permissions->has($user, $organizationId, Permission::SubmissionsReview);
    }

    public function hasAssignedTasks(User $user): bool
    {
        return Task::query()->where('assignee_user_id', $user->id)->exists();
    }

    public function canComplete(User $user, Task $task): bool
    {
        if (! $user->isActive() || $task->organization_id !== $this->tenant->id()) {
            return false;
        }
        if ($task->assignee_user_id !== $user->id && ! $this->mayReview($user)) {
            return false;
        }
        $submission = Submission::query()->find($task->submission_uuid);

        return $submission instanceof Submission && $this->visibility->canView($user, $submission);
    }

    /**
     * Tasks assigned to the user or their groups that the user may complete (or decided: completed tasks
     * stay listed for the decider).
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function actionable(Builder $query, User $user): Builder
    {
        return $this->assigned($query, $user)
            ->whereIn('submission_uuid', $this->visibility->apply(Submission::query(), $user)->select('uuid'));
    }

    /**
     * Tasks assigned to the user, or to one of their groups when they hold `submissions.review` — whether or
     * not they can still see the submission (the API answers 409 for closed ones instead of 404).
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function assigned(Builder $query, User $user): Builder
    {
        $groupIds = $this->mayReview($user) ? $this->groupIds($user) : [];

        return $query->where(function (Builder $q) use ($user, $groupIds): void {
            $q->where('assignee_user_id', $user->id);
            if ($groupIds !== []) {
                $q->orWhereIn('assignee_group_id', $groupIds);
            }
        });
    }

    /**
     * API `open_task_id` for a page of submissions in one query: per submission the lowest open task that is
     * assigned to the user (or their group) and that they may complete.
     *
     * @param  list<string>  $uuids
     * @return array<string, int>
     */
    public function openTaskIdsFor(User $user, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }
        $ids = [];
        $tasks = $this->actionable(Task::query(), $user)->whereIn('submission_uuid', $uuids)
            ->where('status', TaskStatus::Open)->orderBy('id')->get(['id', 'submission_uuid']);
        foreach ($tasks as $task) {
            $ids[$task->submission_uuid] ??= $task->id;
        }

        return $ids;
    }

    /** Any open task of the submission the user may complete (admin inbox: reviewers may decide unassigned tasks). */
    public function completableTaskFor(User $user, Submission $submission): ?Task
    {
        $task = Task::query()->where('submission_uuid', $submission->uuid)->where('status', TaskStatus::Open)->orderBy('id')->first();

        return $task instanceof Task && $this->canComplete($user, $task) ? $task : null;
    }

    /** @return list<int> */
    public function groupIds(User $user): array
    {
        return array_values(DB::table('group_user')->where('user_id', $user->id)->where('organization_id', $this->tenant->require())
            ->pluck('group_id')->map(fn (mixed $id): int => (int) $id)->all());
    }
}
