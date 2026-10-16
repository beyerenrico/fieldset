<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Spatie\Permission\PermissionRegistrar;

/** Test job without models: records the tenant and spatie team id it runs with. */
final class InspectTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var list<array{tenant: int|null, team: int|string|null}> */
    public static array $seen = [];

    public function handle(TenantContext $tenant, PermissionRegistrar $registrar): void
    {
        self::$seen[] = ['tenant' => $tenant->id(), 'team' => $registrar->getPermissionsTeamId()];
    }
}
