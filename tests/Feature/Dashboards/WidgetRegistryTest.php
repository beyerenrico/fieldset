<?php

declare(strict_types=1);

use App\Domain\Dashboards\DashboardContext;
use App\Domain\Dashboards\DateRange;
use App\Domain\Dashboards\Widgets\DashboardWidgetRegistry;
use App\Domain\Dashboards\Widgets\DashboardWidgetSource;
use App\Domain\Dashboards\Widgets\RoleWidgetSource;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Widgets\KpiOverviewWidget;
use App\Filament\Admin\Widgets\RecentSubmissionsWidget;
use App\Filament\Admin\Widgets\ReviewQueueWidget;
use App\Filament\Admin\Widgets\SubmissionsChartWidget;
use App\Filament\Admin\Widgets\WorkflowFailuresWidget;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\WidgetConfiguration;
use Livewire\Livewire;

/* M5-T04 extension point: further widget sources (pinned saved reports) plug in through config('dashboards.widget_sources'). */

/** A stand-in for the pinned-report source: two configured instances of one widget class plus a duplicate role widget. */
final class PinnedStubSource implements DashboardWidgetSource
{
    public function widgetsFor(DashboardContext $context): array
    {
        return [
            RecentSubmissionsWidget::make(['pinned' => 1]),
            RecentSubmissionsWidget::make(['pinned' => 2]),
            KpiOverviewWidget::class,
        ];
    }
}

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
});

it('appends the widgets of further sources after the role widgets, keeping configured instances and dropping duplicates', function (): void {
    config(['dashboards.widget_sources' => [RoleWidgetSource::class, PinnedStubSource::class]]);
    actingInAdmin($this->owner, $this->org);

    $widgets = Livewire::test(Dashboard::class)->instance()->getWidgets();

    expect($widgets)->toHaveCount(6)
        ->and(array_slice($widgets, 0, 4))->toBe([AccountWidget::class, KpiOverviewWidget::class, SubmissionsChartWidget::class, WorkflowFailuresWidget::class])
        ->and($widgets[4])->toBeInstanceOf(WidgetConfiguration::class)
        ->and($widgets[4]->getProperties())->toBe(['pinned' => 1])
        ->and($widgets[5]->getProperties())->toBe(['pinned' => 2])
        ->and(collect($widgets)->filter(fn ($w): bool => $w === KpiOverviewWidget::class))->toHaveCount(1);
});

it('offers a widget class served by any configured source', function (): void {
    config(['dashboards.widget_sources' => [PinnedStubSource::class]]);
    $context = new DashboardContext($this->owner, $this->org->id, DateRange::lastDays(7, 'UTC'));
    $registry = app(DashboardWidgetRegistry::class);

    expect($registry->offers($context, RecentSubmissionsWidget::class))->toBeTrue()
        ->and($registry->offers($context, ReviewQueueWidget::class))->toBeFalse();
});

it('rejects a configured source that does not implement the contract', function (): void {
    config(['dashboards.widget_sources' => [stdClass::class]]);
    $context = new DashboardContext($this->owner, $this->org->id, DateRange::lastDays(7, 'UTC'));

    expect(fn () => app(DashboardWidgetRegistry::class)->widgetsFor($context))->toThrow(InvalidArgumentException::class);
});
