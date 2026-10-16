<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\Permission as PermissionEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Ensures every capability in the catalog exists as a (global) spatie permission. */
final readonly class SyncPermissionCatalog
{
    public function __construct(private PermissionRegistrar $registrar) {}

    public function handle(): void
    {
        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        $this->registrar->forgetCachedPermissions();
    }
}
