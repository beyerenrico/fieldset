<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\Permission as PermissionEnum;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Tenancy\TenantContext;

/** Creates the six system roles for an organization with the §8.6 permission matrix. */
final readonly class SeedOrganizationRoles
{
    public function __construct(
        private SyncPermissionCatalog $catalog,
        private TenantContext $tenant,
    ) {}

    public function handle(Organization $organization): void
    {
        $this->catalog->handle();

        $this->tenant->run($organization, function () use ($organization): void {
            foreach (SystemRole::cases() as $systemRole) {
                $role = Role::query()->firstOrCreate([
                    'name' => $systemRole->value,
                    'guard_name' => 'web',
                    'organization_id' => $organization->id,
                ]);

                $role->syncPermissions(array_map(
                    static fn (PermissionEnum $p): string => $p->value,
                    $systemRole->permissions(),
                ));
            }
        });
    }
}
