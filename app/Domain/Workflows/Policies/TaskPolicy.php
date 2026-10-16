<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Tasks\TaskAccess;
use App\Policies\TenantPolicy;

/**
 * Review tasks (§8.3): only users holding `submissions.review` (or the assignee user) who can see the
 * submission may view and complete them. Tasks are never edited or deleted directly.
 */
final class TaskPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return app(TaskAccess::class)->mayReview($user) || app(TaskAccess::class)->hasAssignedTasks($user);
    }

    public function view(User $user, Task $task): bool
    {
        return $this->owns($task) && app(TaskAccess::class)->canComplete($user, $task);
    }

    public function complete(User $user, Task $task): bool
    {
        return $this->view($user, $task) && $task->isOpen();
    }

    public function update(User $user, Task $task): bool
    {
        return false;
    }

    public function delete(User $user, Task $task): bool
    {
        return false;
    }
}
