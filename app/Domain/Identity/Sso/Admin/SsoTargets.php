<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\RoleAssignmentGuard;
use Illuminate\Validation\Validator;

/**
 * Roles and groups an identity provider may assign (ADR 0036): the JIT default role and group, and claim
 * mapping targets. They belong to the provider's organization; Owner, and any role carrying Owner-level
 * permissions (organization.delete), is never a target. A role the actor sets must also be one they may
 * assign themselves (RoleAssignmentGuard), like a claim mapping (SaveClaimMapping).
 */
final readonly class SsoTargets
{
    public function __construct(private RoleAssignmentGuard $guard) {}

    public function role(Organization|int $org, mixed $id): ?Role
    {
        if (! is_numeric($id)) {
            return null;
        }

        return Role::query()->where('organization_id', $this->id($org))->whereKey((int) $id)->first();
    }

    public function group(Organization|int $org, mixed $id): ?Group
    {
        if (! is_numeric($id)) {
            return null;
        }

        return Group::query()->withoutTenant()->where('organization_id', $this->id($org))->whereKey((int) $id)->first();
    }

    public function isOwnerLevel(Role $role): bool
    {
        return $role->name === SystemRole::Owner->value
            || $role->permissions()->where('name', Permission::OrganizationDelete->value)->exists();
    }

    /**
     * Validator hook for the JIT default role and group of SaveIdentityProvider, run at every save: the role
     * exists and is not Owner-level; a role the actor sets (new or changed against `$storedRoleId`) must be
     * assignable by them.
     *
     * @param  array<string, mixed>  $data
     */
    public function check(Validator $validator, Organization $org, array $data, ?User $actor = null, ?int $storedRoleId = null): void
    {
        $roleId = $data['default_role_id'] ?? null;
        if (filled($roleId) && ! $validator->errors()->has('default_role_id')) {
            $role = $this->role($org, $roleId);
            if (! $role instanceof Role) {
                $validator->errors()->add('default_role_id', __('sso_admin.provider.errors.unknown_role'));
            } elseif ($this->isOwnerLevel($role)) {
                $validator->errors()->add('default_role_id', __('sso_admin.provider.errors.owner_role'));
            } elseif ($actor instanceof User && $role->id !== $storedRoleId
                && ! in_array($role->name, $this->guard->assignableRoles($actor, $org), true)) {
                $validator->errors()->add('default_role_id', __('sso_admin.provider.errors.role_not_assignable'));
            }
        }

        $groupId = $data['default_group_id'] ?? null;
        if (filled($groupId) && ! $validator->errors()->has('default_group_id') && ! $this->group($org, $groupId) instanceof Group) {
            $validator->errors()->add('default_group_id', __('sso_admin.provider.errors.unknown_group'));
        }
    }

    /** @return array<int, string> role id => label, without Owner-level roles and, with an actor, only roles they may assign */
    public function roleOptions(Organization $org, ?User $actor = null): array
    {
        $assignable = $actor instanceof User ? $this->guard->assignableRoles($actor, $org) : null;
        $options = [];
        foreach (Role::query()->where('organization_id', $org->id)->with('permissions')->orderBy('name')->get() as $role) {
            if ($role->name !== SystemRole::Owner->value && ! $role->permissions->contains('name', Permission::OrganizationDelete->value)
                && ($assignable === null || in_array($role->name, $assignable, true))) {
                $options[$role->id] = SystemRole::tryFrom($role->name)?->label() ?? $role->name;
            }
        }

        return $options;
    }

    /** @return array<int, string> group id => name */
    public function groupOptions(Organization $org): array
    {
        /** @var array<int, string> */
        return Group::query()->withoutTenant()->where('organization_id', $org->id)->orderBy('name')->pluck('name', 'id')->all();
    }

    private function id(Organization|int $org): int
    {
        return $org instanceof Organization ? $org->id : $org;
    }
}
