<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Prevents privilege escalation when roles or role permissions change (§8.6, security review M0):
 * - nobody grants permissions they do not hold themselves;
 * - only holders of organization.delete (Owners) touch roles that carry organization.delete;
 * - nobody edits their own roles;
 * - an organization always keeps at least one active Owner.
 */
final readonly class RoleAssignmentGuard
{
    public function __construct(private OrganizationPermissions $permissions) {}

    /** @return list<string> role names the actor may assign or remove in the organization */
    public function assignableRoles(User $actor, Organization $organization): array
    {
        $own = $this->permissions->for($actor, $organization);

        return array_values(Role::query()->where('organization_id', $organization->id)->with('permissions')->get()
            ->filter(fn (Role $role): bool => array_diff($role->permissions->pluck('name')->all(), $own) === [])
            ->pluck('name')
            ->map(fn (mixed $n): string => (string) $n)
            ->all());
    }

    /**
     * @param  list<string>  $currentRoles
     * @param  list<string>  $newRoles
     */
    public function authorizeRoleChange(User $actor, Organization $organization, ?User $target, array $currentRoles, array $newRoles): void
    {
        if ($target instanceof User && $target->is($actor)) {
            throw ValidationException::withMessages(['roles' => __('identity.member.cannot_edit_own_roles')]);
        }

        $assignable = $this->assignableRoles($actor, $organization);
        $changed = array_merge(array_diff($newRoles, $currentRoles), array_diff($currentRoles, $newRoles));

        if (array_diff($changed, $assignable) !== []) {
            throw ValidationException::withMessages(['roles' => __('identity.member.role_not_assignable')]);
        }
    }

    /** @param  list<string>  $permissionNames */
    public function authorizePermissionGrant(User $actor, Organization $organization, array $permissionNames): void
    {
        if (array_diff($permissionNames, $this->permissions->for($actor, $organization)) !== []) {
            throw ValidationException::withMessages(['permissions' => __('identity.role.permission_not_grantable')]);
        }
    }

    /** Throws when no other active Owner would remain. Call inside a transaction. */
    public function ensureAnotherOwnerRemains(Organization $organization, User $leaving): void
    {
        $owners = Membership::query()
            ->where('organization_id', $organization->id)
            ->where('status', MembershipStatus::Active->value)
            ->where('user_id', '!=', $leaving->id)
            ->lockForUpdate()
            ->with('user')
            ->get()
            ->filter(fn (Membership $m): bool => $this->permissions->has($m->user, $organization, Permission::OrganizationDelete));

        if ($owners->isEmpty()) {
            throw ValidationException::withMessages(['roles' => __('identity.member.last_owner')]);
        }
    }

    public function isOwner(User $user, Organization $organization): bool
    {
        return $this->permissions->has($user, $organization, Permission::OrganizationDelete);
    }
}
