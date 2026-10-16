<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Domain\Identity\Support\RoleAssignmentGuard;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates or updates a role and its capabilities. The actor can never grant capabilities they do not
 * hold (removing is always allowed). Writes one audit entry per change.
 */
final readonly class SaveRole
{
    public function __construct(
        private RoleAssignmentGuard $guard,
        private OrganizationPermissions $permissions,
        private PermissionRegistrar $registrar,
        private AuditLogger $audit,
    ) {}

    /** @param  list<int|string>  $permissionIds */
    public function handle(User $actor, Organization $organization, ?Role $role, ?string $name, array $permissionIds): Role
    {
        $names = $this->permissionNames($permissionIds);
        $isNew = ! $role instanceof Role;
        $beforePermissions = $isNew ? [] : $this->sorted($role->permissions()->pluck('name')->all());
        $beforeName = $role?->name;

        $this->guard->authorizePermissionGrant($actor, $organization, array_values(array_diff($names, $beforePermissions)));

        $role = DB::transaction(function () use ($organization, $role, $name, $names): Role {
            $role ??= new Role(['guard_name' => 'web']);
            if ($name !== null && ! $role->isSystemRole()) {
                $role->name = $name;
            }
            $role->organization_id = $organization->id;
            $role->save();
            $role->syncPermissions($names);

            return $role;
        });

        $this->registrar->forgetCachedPermissions();
        $this->permissions->forget();

        if ($isNew) {
            $this->audit->record('role.created', $role, ['name' => $role->name, 'permissions' => $names], $actor);
        } elseif ($beforePermissions !== $names || $beforeName !== $role->name) {
            $this->audit->record('role.updated', $role, [
                'before' => ['name' => $beforeName, 'permissions' => $beforePermissions],
                'after' => ['name' => $role->name, 'permissions' => $names],
            ], $actor);
        }

        return $role;
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<string>
     */
    private function permissionNames(array $ids): array
    {
        return $this->sorted(Permission::query()->whereKey($ids)->pluck('name')->all());
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $values = array_map(static fn (mixed $v): string => (string) $v, $values);
        sort($values);

        return $values;
    }
}
