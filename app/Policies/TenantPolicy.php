<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for policies of organization-owned records. Tenant scoping is not authorization (§6.2):
 * every check needs a capability in the current organization and a record from that organization.
 */
abstract class TenantPolicy
{
    protected function allows(User $user, Permission $permission): bool
    {
        $organizationId = app(TenantContext::class)->id();

        return $organizationId !== null && $user->isActive() && $user->hasPermissionIn($organizationId, $permission);
    }

    protected function owns(Model $record): bool
    {
        $attributes = $record->getAttributes();

        return isset($attributes['organization_id'])
            && (int) $attributes['organization_id'] === app(TenantContext::class)->id();
    }

    protected function allowsFor(User $user, Model $record, Permission $permission): bool
    {
        return $this->owns($record) && $this->allows($user, $permission);
    }
}
