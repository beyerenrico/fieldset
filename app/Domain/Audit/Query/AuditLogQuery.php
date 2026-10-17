<?php

declare(strict_types=1);

namespace App\Domain\Audit\Query;

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * The audit entries of one organization (the activity log is not tenant-scoped by a global scope; ADR 0038):
 * every reader goes through here, with the organization of the current tenant.
 */
final class AuditLogQuery
{
    /** @return Builder<Activity> */
    public static function forCurrentOrganization(): Builder
    {
        return self::forOrganization(app(TenantContext::class)->require());
    }

    /** @return Builder<Activity> */
    public static function forOrganization(int $organizationId): Builder
    {
        return Activity::query()->where('organization_id', $organizationId);
    }

    /** @return list<string> events recorded in the organization, for the event filter */
    public static function events(int $organizationId): array
    {
        return self::distinct($organizationId, 'event');
    }

    /** @return list<string> subject types recorded in the organization, for the subject type filter */
    public static function subjectTypes(int $organizationId): array
    {
        return self::distinct($organizationId, 'subject_type');
    }

    /** @return list<string> */
    private static function distinct(int $organizationId, string $column): array
    {
        return array_values(array_filter(
            self::forOrganization($organizationId)->whereNotNull($column)->distinct()->orderBy($column)->pluck($column)->all(),
            is_string(...),
        ));
    }
}
