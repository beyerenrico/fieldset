<?php

declare(strict_types=1);

namespace App\Domain\Dashboards;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Domain\Identity\Tenancy\TenantContext;

/** The viewer, their organization (the current tenant) and the dashboard date range. */
final readonly class DashboardContext
{
    public function __construct(
        public User $user,
        public int $organizationId,
        public DateRange $range,
    ) {}

    /** For the current tenant; throws when none is set. */
    public static function current(User $user, DateRange $range): self
    {
        return new self($user, app(TenantContext::class)->require(), $range);
    }

    public function can(Permission $permission): bool
    {
        return app(OrganizationPermissions::class)->has($this->user, $this->organizationId, $permission);
    }

    public function seesAllSubmissions(): bool
    {
        return $this->can(Permission::SubmissionsViewAll);
    }

    public function withRange(DateRange $range): self
    {
        return new self($this->user, $this->organizationId, $range);
    }

    public function cacheSegment(CacheScope $scope): string
    {
        return match ($scope) {
            CacheScope::Organization => 'org',
            CacheScope::Visibility => $this->seesAllSubmissions() ? 'all' : 'user:'.$this->user->id,
            CacheScope::User => 'user:'.$this->user->id,
        };
    }
}
