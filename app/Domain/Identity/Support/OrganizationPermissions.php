<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a user's permissions in a given organization without switching the global spatie
 * team id. Memoized per request (scoped binding).
 */
final class OrganizationPermissions
{
    /** @var array<string, list<string>> */
    private array $cache = [];

    public function has(User $user, Organization|int $organization, Permission $permission): bool
    {
        return in_array($permission->value, $this->for($user, $organization), true);
    }

    /** @return list<string> */
    public function for(User $user, Organization|int $organization): array
    {
        $orgId = $organization instanceof Organization ? $organization->id : $organization;
        $key = $user->id.':'.$orgId;

        return $this->cache[$key] ??= $this->load($user, $orgId);
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /** @return list<string> */
    private function load(User $user, int $orgId): array
    {
        $tables = config('permission.table_names');

        $viaRoles = DB::table($tables['model_has_roles'].' as mhr')
            ->join($tables['role_has_permissions'].' as rhp', 'rhp.role_id', '=', 'mhr.role_id')
            ->join($tables['permissions'].' as p', 'p.id', '=', 'rhp.permission_id')
            ->where('mhr.model_type', $user->getMorphClass())
            ->where('mhr.model_id', $user->id)
            ->where('mhr.organization_id', $orgId)
            ->pluck('p.name');

        $direct = DB::table($tables['model_has_permissions'].' as mhp')
            ->join($tables['permissions'].' as p', 'p.id', '=', 'mhp.permission_id')
            ->where('mhp.model_type', $user->getMorphClass())
            ->where('mhp.model_id', $user->id)
            ->where('mhp.organization_id', $orgId)
            ->pluck('p.name');

        return array_values(array_unique([...$viaRoles->all(), ...$direct->all()]));
    }
}
