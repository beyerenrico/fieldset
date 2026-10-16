<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Concerns;

use App\Domain\Dashboards\DashboardContext;
use App\Domain\Dashboards\DashboardData;
use App\Domain\Dashboards\DateRange;
use App\Domain\Dashboards\Widgets\DashboardWidgetRegistry;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Dashboard widget plumbing: visible (and mountable over Livewire) only when a widget source offers
 * the widget to the current user, reads the dashboard date filter, and loads its data through the
 * cached DashboardData of the current tenant.
 */
trait InteractsWithDashboard
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User || ! Filament::getTenant() instanceof Organization || ! app(TenantContext::class)->check()) {
            return false;
        }

        $context = DashboardContext::current($user, DateRange::fromFilters(null, SubmissionResource::displayTimezone()));

        return app(DashboardWidgetRegistry::class)->offers($context, static::class);
    }

    protected function dashboardContext(): DashboardContext
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return DashboardContext::current($user, DateRange::fromFilters($this->pageFilters, SubmissionResource::displayTimezone()));
    }

    protected function dashboardData(): DashboardData
    {
        return app(DashboardData::class);
    }

    /** "As of 14:05" for a widget's cached data (review U3: cached numbers can lag up to the TTL). */
    protected function freshness(string $widget): ?string
    {
        $at = $this->dashboardData()->computedAt($this->dashboardContext(), $widget);

        return $at === null ? null : (string) __('dashboard.cached_at', [
            'time' => $at->setTimezone(SubmissionResource::displayTimezone())->settings(['locale' => app()->getLocale()])->isoFormat('LT'),
        ]);
    }

    /** The range label, followed by the freshness of the widget's cached data. */
    protected function rangeAndFreshness(string $widget): string
    {
        return implode(' · ', array_filter([$this->dashboardContext()->range->label(), $this->freshness($widget)]));
    }

    /**
     * Rows keyed by a unique column (Filament custom-data tables use the keys as record ids).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    protected static function keyed(array $rows, string $key): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(string) $row[$key]] = $row;
        }

        return $keyed;
    }
}
