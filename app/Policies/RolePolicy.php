<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;

final class RolePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::MembersManage);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->allowsFor($user, $role, Permission::MembersManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::MembersManage);
    }

    /** The Owner role is fixed so an organization can never lock out its owners. */
    public function update(User $user, Role $role): bool
    {
        return $role->name !== SystemRole::Owner->value && $this->allowsFor($user, $role, Permission::MembersManage);
    }

    /** System roles cannot be deleted; custom roles can. */
    public function delete(User $user, Role $role): bool
    {
        return SystemRole::tryFrom($role->name) === null && $this->allowsFor($user, $role, Permission::MembersManage);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
