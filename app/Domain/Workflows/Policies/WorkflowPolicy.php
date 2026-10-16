<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Models\Workflow;
use App\Policies\TenantPolicy;

/** Workflows need `workflows.manage` (§8.6 row "Workflows": Owner, Admin, Designer). */
final class WorkflowPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::WorkflowsManage);
    }

    public function view(User $user, Workflow $workflow): bool
    {
        return $this->allowsFor($user, $workflow, Permission::WorkflowsManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::WorkflowsManage);
    }

    public function update(User $user, Workflow $workflow): bool
    {
        return $this->allowsFor($user, $workflow, Permission::WorkflowsManage);
    }

    /** Only workflows that never ran: runs keep the history of what happened to submissions. */
    public function delete(User $user, Workflow $workflow): bool
    {
        return $this->allowsFor($user, $workflow, Permission::WorkflowsManage) && ! $workflow->runs()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
