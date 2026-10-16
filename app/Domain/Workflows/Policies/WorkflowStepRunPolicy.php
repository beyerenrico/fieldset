<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Models\WorkflowStepRun;
use App\Policies\TenantPolicy;

/** Step runs are part of a run's timeline: read-only for workflow managers. */
final class WorkflowStepRunPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::WorkflowsManage);
    }

    public function view(User $user, WorkflowStepRun $step): bool
    {
        return $this->allowsFor($user, $step, Permission::WorkflowsManage);
    }

    public function update(User $user, WorkflowStepRun $step): bool
    {
        return false;
    }

    public function delete(User $user, WorkflowStepRun $step): bool
    {
        return false;
    }
}
