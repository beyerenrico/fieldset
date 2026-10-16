<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Policies\TenantPolicy;

/** Runs are visible to workflow managers; a failed run may be retried from its failed step. */
final class WorkflowRunPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::WorkflowsManage);
    }

    public function view(User $user, WorkflowRun $run): bool
    {
        return $this->allowsFor($user, $run, Permission::WorkflowsManage);
    }

    public function retry(User $user, WorkflowRun $run): bool
    {
        return $this->view($user, $run) && $run->status === RunStatus::Failed;
    }

    public function update(User $user, WorkflowRun $run): bool
    {
        return false;
    }

    public function delete(User $user, WorkflowRun $run): bool
    {
        return false;
    }
}
