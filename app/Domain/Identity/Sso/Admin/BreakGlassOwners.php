<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The break-glass account of an enforcing organization (ADR 0036, Enforcement): an active user with an
 * active membership, Owner rights in that organization (organization.delete, like RoleAssignmentGuard and
 * the password gate) and confirmed MFA (TOTP). Checked when enforcement is switched on (SetSsoEnforcement)
 * and offered by the admin page; the password gate applies the same rule at every login.
 */
final readonly class BreakGlassOwners
{
    /**
     * Active Owners of the organization (with or without MFA), by name.
     *
     * @return Collection<int, User>
     */
    public function owners(Organization|int $org): Collection
    {
        return $this->ownersQuery($this->id($org))->orderBy('users.name')->orderBy('users.id')->get();
    }

    /** null when eligible, else the reason: not_owner | no_mfa */
    public function problem(User $user, Organization|int $org): ?string
    {
        if (! $this->ownersQuery($this->id($org))->whereKey($user->id)->exists()) {
            return 'not_owner';
        }

        return $user->two_factor_confirmed_at === null || $user->two_factor_secret === null ? 'no_mfa' : null;
    }

    public function isEligible(User $user, Organization|int $org): bool
    {
        return $this->problem($user, $org) === null;
    }

    /** @return Builder<User> */
    private function ownersQuery(int $orgId): Builder
    {
        return PermissionHolders::query($orgId, Permission::OrganizationDelete);
    }

    private function id(Organization|int $org): int
    {
        return $org instanceof Organization ? $org->id : $org;
    }
}
