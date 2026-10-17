<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\Role;
use Illuminate\Validation\Validator;

/**
 * Roles and groups an identity provider may assign (ADR 0036): the JIT default role and group, and claim
 * mapping targets. They belong to the provider's organization; Owner, and any role carrying Owner-level
 * permissions (organization.delete), is never a target.
 */
final readonly class SsoTargets
{
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
     * Validator hook for the JIT default role and group of SaveIdentityProvider.
     *
     * @param  array<string, mixed>  $data
     */
    public function check(Validator $validator, Organization $org, array $data): void
    {
        $roleId = $data['default_role_id'] ?? null;
        if (filled($roleId) && ! $validator->errors()->has('default_role_id')) {
            $role = $this->role($org, $roleId);
            if (! $role instanceof Role) {
                $validator->errors()->add('default_role_id', __('sso_admin.provider.errors.unknown_role'));
            } elseif ($this->isOwnerLevel($role)) {
                $validator->errors()->add('default_role_id', __('sso_admin.provider.errors.owner_role'));
            }
        }

        $groupId = $data['default_group_id'] ?? null;
        if (filled($groupId) && ! $validator->errors()->has('default_group_id') && ! $this->group($org, $groupId) instanceof Group) {
            $validator->errors()->add('default_group_id', __('sso_admin.provider.errors.unknown_group'));
        }
    }

    /** @return array<int, string> role id => label, without Owner-level roles */
    public function roleOptions(Organization $org): array
    {
        $options = [];
        foreach (Role::query()->where('organization_id', $org->id)->with('permissions')->orderBy('name')->get() as $role) {
            if ($role->name !== SystemRole::Owner->value && ! $role->permissions->contains('name', Permission::OrganizationDelete->value)) {
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
