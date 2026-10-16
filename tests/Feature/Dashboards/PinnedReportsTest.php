<?php

declare(strict_types=1);

use App\Domain\Dashboards\DateRange as DashboardRange;
use App\Domain\Dashboards\Models\DashboardPin;
use App\Domain\Dashboards\Pins\DashboardPins;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Reports\Filters\DateRange;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\SavedReportQuery;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Resources\SavedReports\Pages\ListSavedReports;
use App\Filament\Admin\Resources\SavedReports\Pages\ViewSavedReport;
use App\Filament\Admin\Resources\SavedReports\SavedReportResource;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use App\Filament\Admin\Widgets\PinnedReportWidget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Dashboards\DashboardFixtures as D;
use Tests\Feature\Reports\ReportsScenario;

/* §8.1 v1.0 + §8.4: saved reports pinned to the dashboard as widgets, per user, computed for the viewer. */

const PINNED_RANGE = ['startDate' => '2026-10-01', 'endDate' => '2026-10-07'];

beforeEach(function (): void {
    $this->s = ReportsScenario::make();
    $s = $this->s;
    $s->submit($s->worker, ['amount' => '0.10', 'kind' => 'repair'], submittedAt: '2026-10-01 10:00');
    $s->submit($s->worker, ['amount' => '0.20', 'kind' => 'repair'], submittedAt: '2026-10-02 10:00');
    $s->submit($s->owner, ['amount' => '5', 'kind' => 'maintenance'], submittedAt: '2026-10-03 10:00');
    $s->submit($s->worker, ['amount' => '100', 'kind' => 'maintenance'], submittedAt: '2026-10-20 10:00'); // outside PINNED_RANGE
});

/** @param  array<string, mixed>  $attributes */
function reportToPin(ReportsScenario $s, array $attributes = []): SavedReport
{
    return app(TenantContext::class)->run($s->org, fn (): SavedReport => SavedReport::factory()->create([
        'form_id' => $s->form->id, 'name' => 'Revenue by kind', 'group_by' => 'field:kind', 'metric' => 'sum', 'metric_key' => 'amount',
        'visibility' => 'organization', 'created_by' => $s->owner->id, ...$attributes,
    ]));
}

function pinReport(ReportsScenario $s, User $user, SavedReport $report): DashboardPin
{
    return app(TenantContext::class)->run($s->org, fn (): DashboardPin => app(DashboardPins::class)->pin($user, $report));
}

/** @param  array<string, string>  $filters */
function pinnedWidget(SavedReport $report, array $filters = PINNED_RANGE): Testable
{
    return Livewire::test(PinnedReportWidget::class, ['savedReportId' => $report->id, 'pageFilters' => $filters]);
}

/** @return list<array<string, mixed>> properties of the pinned-report widgets on the current user's dashboard */
function pinnedWidgetsOnDashboard(): array
{
    return array_values(array_map(
        fn (WidgetConfiguration $widget): array => $widget->getProperties(),
        array_filter(Livewire::test(Dashboard::class)->instance()->getWidgets(), fn ($w): bool => $w instanceof WidgetConfiguration && $w->widget === PinnedReportWidget::class),
    ));
}

/** @return array{labels: list<string>, values: list<string|null>} */
function expectedChart(SavedReport $report, User $viewer): array
{
    $range = DashboardRange::fromFilters(PINNED_RANGE, SubmissionResource::displayTimezone());

    return app(SavedReportQuery::class)->run($report->refresh(), new DateRange($range->startUtc(), $range->endUtc()), $viewer)->chart();
}

it('pins and unpins a report from the saved reports table', function (): void {
    $report = reportToPin($this->s);
    actingInAdmin($this->s->owner, $this->s->org);

    Livewire::test(ListSavedReports::class)
        ->assertTableActionVisible('pin', $report)->assertTableActionHidden('unpin', $report)
        ->callTableAction('pin', $report)->assertNotified(__('reports.saved.pinned'));
    expect(DashboardPin::query()->where('user_id', $this->s->owner->id)->pluck('saved_report_id')->all())->toBe([$report->id]);

    Livewire::test(ListSavedReports::class)
        ->assertTableActionHidden('pin', $report)->assertTableActionVisible('unpin', $report)
        ->callTableAction('unpin', $report)->assertNotified(__('reports.saved.unpinned'));
    expect(DashboardPin::query()->count())->toBe(0);
});

it('pins and unpins from the report page, separately for each user', function (): void {
    $report = reportToPin($this->s);
    $viewer = memberOf($this->s->org, SystemRole::Viewer);
    actingInAdmin($viewer, $this->s->org);

    Livewire::test(ViewSavedReport::class, ['record' => $report->id])->assertActionVisible('pin')->assertActionHidden('unpin')->callAction('pin');
    Livewire::test(ViewSavedReport::class, ['record' => $report->id])->assertActionHidden('pin')->assertActionVisible('unpin');

    actingInAdmin($this->s->owner, $this->s->org);
    Livewire::test(ViewSavedReport::class, ['record' => $report->id])->assertActionVisible('pin')->assertActionHidden('unpin');

    actingInAdmin($viewer, $this->s->org);
    Livewire::test(ViewSavedReport::class, ['record' => $report->id])->callAction('unpin');
    expect(DashboardPin::query()->count())->toBe(0);
});

it('pins a report once and only when the user may view it', function (): void {
    $report = reportToPin($this->s);
    pinReport($this->s, $this->s->owner, $report);
    pinReport($this->s, $this->s->owner, $report);
    $private = reportToPin($this->s, ['visibility' => 'private']);

    expect(app(TenantContext::class)->run($this->s->org, fn (): int => DashboardPin::query()->count()))->toBe(1)
        ->and(fn () => pinReport($this->s, memberOf($this->s->org, SystemRole::Designer), $private))->toThrow(AuthorizationException::class)
        ->and(fn () => pinReport($this->s, $this->s->worker, $report))->toThrow(AuthorizationException::class);
});

it('adds one widget per pin after the role widgets, in pin order', function (): void {
    $first = reportToPin($this->s, ['name' => 'First']);
    $second = reportToPin($this->s, ['name' => 'Second']);
    pinReport($this->s, $this->s->owner, $second);
    pinReport($this->s, $this->s->owner, $first);
    actingInAdmin($this->s->owner, $this->s->org);

    $widgets = Livewire::test(Dashboard::class)->instance()->getWidgets();

    expect(pinnedWidgetsOnDashboard())->toBe([['savedReportId' => $second->id], ['savedReportId' => $first->id]])
        ->and(array_map(fn ($w): string => $w instanceof WidgetConfiguration ? $w->widget : $w, array_slice($widgets, -2)))->toBe([PinnedReportWidget::class, PinnedReportWidget::class]);
    $this->get(Dashboard::getUrl())->assertOk()->assertSeeLivewire(PinnedReportWidget::class);
});

it('charts the numbers SavedReportQuery computes for the dashboard range and the report filter', function (): void {
    $report = reportToPin($this->s);
    $narrowed = reportToPin($this->s, ['name' => 'From 2 October', 'filters' => ['from' => '2026-10-02']]);
    pinReport($this->s, $this->s->owner, $report);
    pinReport($this->s, $this->s->owner, $narrowed);
    actingInAdmin($this->s->owner, $this->s->org);

    $widget = pinnedWidget($report)->assertOk()->assertSee('Revenue by kind');
    $data = D::call($widget, 'getData');
    $expected = expectedChart($report, $this->s->owner);

    expect(D::call($widget, 'getType'))->toBe('bar')
        ->and($data['labels'])->toBe($expected['labels'])->toBe(['Repair', 'Maintenance'])
        ->and($data['datasets'][0]['data'])->toBe($expected['values'])->toBe(['0.3', '5'])   // the 100 of 20 October is outside the range
        ->and(D::call(pinnedWidget($narrowed), 'getData')['datasets'][0]['data'])->toBe(expectedChart($narrowed, $this->s->owner)['values'])->toBe(['5', '0.2']);   // a tie: ordered by key
});

it('draws time groups as a line and many groups or a single total as a compact table', function (): void {
    $daily = reportToPin($this->s, ['name' => 'Daily', 'group_by' => 'day', 'metric' => 'count', 'metric_key' => null]);
    $total = reportToPin($this->s, ['name' => 'Total', 'group_by' => null]);
    $grouped = reportToPin($this->s, ['name' => 'Grouped']);
    foreach ([$daily, $total, $grouped] as $report) {
        pinReport($this->s, $this->s->owner, $report);
    }
    actingInAdmin($this->s->owner, $this->s->org);

    expect(D::call(pinnedWidget($daily), 'getType'))->toBe('line')
        ->and(D::call(pinnedWidget($daily), 'getData')['datasets'][0]['data'])->toBe(expectedChart($daily, $this->s->owner)['values'])->toBe(['1', '1', '1']);
    pinnedWidget($total)->assertOk()->assertSeeInOrder([__('reports.result.total'), '5.30 EUR'])->assertDontSee(__('reports.result.none'))->assertDontSee('<canvas', false);

    config(['dashboards.pinned_reports.max_chart_groups' => 1, 'dashboards.pinned_reports.table_rows' => 1]);
    pinnedWidget($grouped)->assertOk()->assertDontSee('<canvas', false)
        ->assertSeeInOrder(['Repair', '0.30 EUR', __('dashboard.pinned.more', ['count' => 1]), __('reports.result.total'), '5.30 EUR'])
        ->assertDontSee('5.00 EUR')
        ->assertSee(e(SavedReportResource::getUrl('view', ['record' => $grouped])), false);
});

it('computes each viewer\'s own numbers and never serves another user\'s cached result', function (): void {
    $reviewer = memberOf($this->s->org, SystemRole::Reviewer);
    $this->s->group('Crew', $reviewer, $this->s->worker);
    $report = reportToPin($this->s, ['metric' => 'count', 'metric_key' => null]);
    pinReport($this->s, $this->s->owner, $report);
    pinReport($this->s, $reviewer, $report);

    actingInAdmin($this->s->owner, $this->s->org);
    $owner = D::call(pinnedWidget($report), 'getData');
    actingInAdmin($reviewer, $this->s->org);
    $reviewerData = D::call(pinnedWidget($report), 'getData');

    expect($owner['labels'])->toBe(['Repair', 'Maintenance'])->and($owner['datasets'][0]['data'])->toBe(['2', '1'])
        ->and($reviewerData['labels'])->toBe(['Repair'])->and($reviewerData['datasets'][0]['data'])->toBe(['2'])   // only the crew's submissions
        ->and($reviewerData['datasets'][0]['data'])->toBe(expectedChart($report, $reviewer)['values']);
});

it('keeps organizations apart', function (): void {
    $other = ReportsScenario::make();
    $other->submit($other->worker, ['amount' => '777', 'kind' => 'repair'], submittedAt: '2026-10-02 10:00');
    $foreign = reportToPin($other, ['name' => 'Foreign revenue']);
    pinReport($other, $other->owner, $foreign);
    $mine = reportToPin($this->s);
    pinReport($this->s, $this->s->owner, $mine);

    actingInAdmin($this->s->owner, $this->s->org);

    expect(pinnedWidgetsOnDashboard())->toBe([['savedReportId' => $mine->id]])
        ->and(DashboardPin::query()->pluck('saved_report_id')->all())->toBe([$mine->id])
        ->and(D::call(pinnedWidget($foreign), 'getData'))->toBe(['datasets' => [], 'labels' => []])
        ->and(fn () => pinReport($this->s, $this->s->owner, $foreign))->toThrow(AuthorizationException::class);
    pinnedWidget($foreign)->assertOk()->assertDontSee('Foreign revenue')->assertDontSee('777');
});

it('shows nothing for a report the user did not pin', function (): void {
    $pinned = reportToPin($this->s, ['name' => 'Pinned']);
    $other = reportToPin($this->s, ['name' => 'Not pinned']);
    pinReport($this->s, $this->s->owner, $pinned);
    actingInAdmin($this->s->owner, $this->s->org);

    pinnedWidget($other)->assertOk()->assertDontSee('Not pinned');
    expect(D::call(pinnedWidget($other), 'getData'))->toBe(['datasets' => [], 'labels' => []]);
});

it('drops the widget and the pin when the viewer loses access or the report is deleted', function (): void {
    $viewer = memberOf($this->s->org, SystemRole::Viewer);
    $report = reportToPin($this->s);
    $kept = reportToPin($this->s, ['name' => 'Kept']);
    pinReport($this->s, $viewer, $report);
    pinReport($this->s, $viewer, $kept);
    actingInAdmin($viewer, $this->s->org);
    $widget = pinnedWidget($report)->assertOk()->assertSee('Revenue by kind');

    $report->update(['visibility' => 'private']);   // the creator stops sharing it

    $widget->call('updateChartData')->assertOk()->assertDontSee('Revenue by kind')->assertDontSee('0.3');
    expect(DashboardPin::query()->pluck('saved_report_id')->all())->toBe([$kept->id])
        ->and(pinnedWidgetsOnDashboard())->toBe([['savedReportId' => $kept->id]]);

    $kept->delete();

    expect(pinnedWidgetsOnDashboard())->toBe([])->and(DashboardPin::query()->count())->toBe(0);
    pinnedWidget($kept)->assertOk()->assertDontSee('Kept');
});
