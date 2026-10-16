<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Permission\PermissionRegistrar;

/** Test job: restores a tenant-owned model (with a loaded relation) and records what it saw. */
final class InspectGroupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var array<string, mixed> */
    public static array $seen = [];

    public function __construct(public Group $group) {}

    public function handle(TenantContext $tenant, PermissionRegistrar $registrar): void
    {
        self::$seen[] = [
            'group_id' => $this->group->id,
            'users' => $this->group->users->count(),
            'tenant' => $tenant->id(),
            'team' => $registrar->getPermissionsTeamId(),
            'visible_groups' => Group::query()->pluck('id')->all(),
        ];
    }
}
