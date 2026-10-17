<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Active users with an active membership who hold a permission in an organization, through a role or
 * directly (spatie teams), in one query and without a tenant: SSO checks and notifications run before one
 * is known (sign-in, scheduled checks).
 */
final class PermissionHolders
{
    /** @return Builder<User> */
    public static function query(int $orgId, Permission $permission): Builder
    {
        $tables = (array) config('permission.table_names');
        $morph = (new User)->getMorphClass();

        return User::query()
            ->where('users.status', UserStatus::Active->value)
            ->whereIn('users.id', fn (QueryBuilder $q) => $q->select('user_id')->from('organization_user')
                ->where('organization_id', $orgId)->where('status', MembershipStatus::Active->value))
            ->where(fn (Builder $q) => $q
                ->whereIn('users.id', fn (QueryBuilder $s) => $s->select('mhr.model_id')->from($tables['model_has_roles'].' as mhr')
                    ->join($tables['role_has_permissions'].' as rhp', 'rhp.role_id', '=', 'mhr.role_id')
                    ->join($tables['permissions'].' as p', 'p.id', '=', 'rhp.permission_id')
                    ->where('mhr.model_type', $morph)->where('mhr.organization_id', $orgId)->where('p.name', $permission->value))
                ->orWhereIn('users.id', fn (QueryBuilder $s) => $s->select('mhp.model_id')->from($tables['model_has_permissions'].' as mhp')
                    ->join($tables['permissions'].' as p', 'p.id', '=', 'mhp.permission_id')
                    ->where('mhp.model_type', $morph)->where('mhp.organization_id', $orgId)->where('p.name', $permission->value)));
    }
}
