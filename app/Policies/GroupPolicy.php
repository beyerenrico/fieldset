<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\User;

final class GroupPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::MembersManage);
    }

    public function view(User $user, Group $group): bool
    {
        return $this->allowsFor($user, $group, Permission::MembersManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::MembersManage);
    }

    public function update(User $user, Group $group): bool
    {
        return $this->allowsFor($user, $group, Permission::MembersManage);
    }

    public function delete(User $user, Group $group): bool
    {
        return $this->allowsFor($user, $group, Permission::MembersManage);
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, Permission::MembersManage);
    }
}
