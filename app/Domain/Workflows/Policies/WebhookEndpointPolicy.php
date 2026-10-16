<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Policies\TenantPolicy;

/** Webhook endpoints belong to workflow configuration (`workflows.manage`). */
final class WebhookEndpointPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::WorkflowsManage);
    }

    public function view(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->allowsFor($user, $endpoint, Permission::WorkflowsManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::WorkflowsManage);
    }

    public function update(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->allowsFor($user, $endpoint, Permission::WorkflowsManage);
    }

    public function delete(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->allowsFor($user, $endpoint, Permission::WorkflowsManage);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
