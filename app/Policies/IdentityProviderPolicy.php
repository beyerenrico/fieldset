<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\User;

/**
 * SSO settings belong to "Users, roles, groups, SSO" (§8.6 row, members.manage: Owner, Admin), within the own
 * organization. Setting up a provider and its security settings (`manageSecurity`: the connection, attribute
 * names, trust in unverified email, the JIT default role, switching enforcement off) also need Owner level
 * (organization.delete; gate review M7): otherwise an Admin could point SSO at their own IdP and sign in as an
 * Owner. SaveIdentityProvider and SetSsoEnforcement enforce the same rule (SsoOwnerGuard).
 */
final class IdentityProviderPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::MembersManage);
    }

    public function view(User $user, IdentityProvider $identityProvider): bool
    {
        return $this->allowsFor($user, $identityProvider, Permission::MembersManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::MembersManage) && $this->allows($user, Permission::OrganizationDelete);
    }

    public function update(User $user, IdentityProvider $identityProvider): bool
    {
        return $this->allowsFor($user, $identityProvider, Permission::MembersManage);
    }

    public function delete(User $user, IdentityProvider $identityProvider): bool
    {
        return $this->allowsFor($user, $identityProvider, Permission::MembersManage);
    }

    public function manageSecurity(User $user, IdentityProvider $identityProvider): bool
    {
        return $this->allowsFor($user, $identityProvider, Permission::MembersManage) && $this->allows($user, Permission::OrganizationDelete);
    }
}
