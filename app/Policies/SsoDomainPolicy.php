<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;

/** SSO settings belong to "Users, roles, groups, SSO" (§8.6 row, members.manage: Owner, Admin), within the own organization. */
final class SsoDomainPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::MembersManage);
    }

    public function view(User $user, SsoDomain $ssoDomain): bool
    {
        return $this->allowsFor($user, $ssoDomain, Permission::MembersManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::MembersManage);
    }

    public function update(User $user, SsoDomain $ssoDomain): bool
    {
        return $this->allowsFor($user, $ssoDomain, Permission::MembersManage);
    }

    public function delete(User $user, SsoDomain $ssoDomain): bool
    {
        return $this->allowsFor($user, $ssoDomain, Permission::MembersManage);
    }
}
