<?php

declare(strict_types=1);

namespace App\Domain\Identity\Tenancy;

use App\Domain\Identity\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned: adds the throwing OrganizationScope, fills organization_id on
 * create and refuses writes that would move a record to another organization.
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model): void {
            $tenant = app(TenantContext::class);
            $current = $model->getAttribute('organization_id');

            if ($current === null) {
                $model->setAttribute('organization_id', $tenant->require());

                return;
            }

            if (! $tenant->isBypassed() && (int) $current !== $tenant->require()) {
                throw TenantNotSetException::crossTenantWrite($model::class);
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('organization_id')) {
                throw TenantNotSetException::crossTenantWrite($model::class);
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Platform-level opt-out for a single query.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutTenant(Builder $query): Builder
    {
        return $query->withoutGlobalScope(OrganizationScope::class);
    }
}
