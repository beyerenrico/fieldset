<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Domain\Identity\Support\RoleAssignmentGuard;
use App\Domain\Identity\Tenancy\TenantContext;
use Spatie\Permission\Models\Permission as PermissionModel;

/** Select options scoped to the current organization. */
final class OrganizationOptions
{
    /** @return array<string, string> role name => label, only roles the current user may assign */
    public static function roles(): array
    {
        $tenant = app(TenantContext::class);
        $actor = auth()->user();
        $organization = $tenant->organization();
        if (! $actor instanceof User || $organization === null) {
            return [];
        }
        $assignable = app(RoleAssignmentGuard::class)->assignableRoles($actor, $organization);

        return Role::query()->where('organization_id', $organization->id)
            ->whereIn('name', $assignable)->orderBy('id')->pluck('name')
            ->mapWithKeys(fn (string $name): array => [$name => self::roleLabel($name)])
            ->all();
    }

    /** @return array<int, string> permission id => label, only permissions the current user holds */
    public static function grantablePermissions(): array
    {
        $tenant = app(TenantContext::class);
        $actor = auth()->user();
        if (! $actor instanceof User || ! $tenant->check()) {
            return [];
        }
        $own = app(OrganizationPermissions::class)->for($actor, $tenant->require());

        return PermissionModel::query()->whereIn('name', $own)->orderBy('id')->get()
            ->mapWithKeys(fn (PermissionModel $p): array => [(int) $p->id => Permission::tryFrom($p->name)?->label() ?? $p->name])
            ->all();
    }

    /** @return array<int, string> */
    public static function groups(): array
    {
        return Group::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function roleLabel(string $name): string
    {
        return SystemRole::tryFrom($name)?->label() ?? $name;
    }

    /** @return list<string> */
    public static function rolesOf(User $user): array
    {
        return array_values(Role::query()
            ->join('model_has_roles as mhr', 'mhr.role_id', '=', 'roles.id')
            ->where('mhr.model_type', $user->getMorphClass())
            ->where('mhr.model_id', $user->id)
            ->where('mhr.organization_id', app(TenantContext::class)->require())
            ->pluck('roles.name')
            ->map(fn (mixed $n): string => (string) $n)
            ->all());
    }

    /** @return list<int> */
    public static function groupIdsOf(User $user): array
    {
        return array_values($user->groups()->withoutTenant()
            ->wherePivot('organization_id', app(TenantContext::class)->require())
            ->pluck('groups.id')->map(fn (mixed $id): int => (int) $id)->all());
    }
}
