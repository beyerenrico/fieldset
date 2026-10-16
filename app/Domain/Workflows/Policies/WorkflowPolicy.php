<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Models\Workflow;
use App\Policies\TenantPolicy;
use Illuminate\Auth\Access\Response;

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

    /** Only workflows that never ran: runs keep the history of what happened to submissions (the denial says so). */
    public function delete(User $user, Workflow $workflow): bool|Response
    {
        if (! $this->allowsFor($user, $workflow, Permission::WorkflowsManage)) {
            return false;
        }

        return $workflow->runs()->exists() ? Response::deny(__('workflows.delete.has_runs')) : true;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
