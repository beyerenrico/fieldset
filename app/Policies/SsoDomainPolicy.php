<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;

/** Skeleton (M7, owner B): SSO settings are organization settings (Owner, Admin). */
final class SsoDomainPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::OrganizationManage);
    }

    public function view(User $user, SsoDomain $ssoDomain): bool
    {
        return $this->allowsFor($user, $ssoDomain, Permission::OrganizationManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::OrganizationManage);
    }

    public function update(User $user, SsoDomain $ssoDomain): bool
    {
        return $this->allowsFor($user, $ssoDomain, Permission::OrganizationManage);
    }

    public function delete(User $user, SsoDomain $ssoDomain): bool
    {
        return $this->allowsFor($user, $ssoDomain, Permission::OrganizationManage);
    }
}
