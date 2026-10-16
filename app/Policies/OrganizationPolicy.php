<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;

final class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->isActive() && $user->isMemberOf($organization);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->isActive() && $user->hasPermissionIn($organization, Permission::OrganizationManage);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->isActive() && $user->hasPermissionIn($organization, Permission::OrganizationDelete);
    }
}
