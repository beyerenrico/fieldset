<?php

declare(strict_types=1);

namespace App\Domain\Identity\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts tenant-owned models to the current organization and throws when none is set.
 *
 * @implements Scope<Model>
 */
final class OrganizationScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class);

        if ($tenant->isBypassed()) {
            return;
        }

        $builder->where($model->qualifyColumn('organization_id'), $tenant->require());
    }
}
