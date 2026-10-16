<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Roles\Pages;

use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Roles\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Spatie\Permission\PermissionRegistrar;

class ManageRoles extends ManageRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()
            ->mutateDataUsing(fn (array $data): array => [
                ...$data,
                'guard_name' => 'web',
                'organization_id' => app(TenantContext::class)->require(),
            ])
            ->after(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions())];
    }
}
