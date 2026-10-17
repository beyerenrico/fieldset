<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Privacy\Models\RetentionPolicy;
use App\Policies\TenantPolicy;

/** Retention is an organization setting (§8.6 "Organization settings": Owner, Admin). */
final class RetentionPolicyPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::OrganizationManage);
    }

    public function view(User $user, RetentionPolicy $policy): bool
    {
        return $this->allowsFor($user, $policy, Permission::OrganizationManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::OrganizationManage);
    }

    public function update(User $user, RetentionPolicy $policy): bool
    {
        return $this->allowsFor($user, $policy, Permission::OrganizationManage);
    }

    public function delete(User $user, RetentionPolicy $policy): bool
    {
        return false;
    }
}
