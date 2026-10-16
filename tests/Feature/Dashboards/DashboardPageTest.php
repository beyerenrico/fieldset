<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Widgets\DraftFormsWidget;
use App\Filament\Admin\Widgets\KpiOverviewWidget;
use App\Filament\Admin\Widgets\RecentlyPublishedWidget;
use App\Filament\Admin\Widgets\RecentSubmissionsWidget;
use App\Filament\Admin\Widgets\ReviewQueueWidget;
use App\Filament\Admin\Widgets\SubmissionsChartWidget;
use App\Filament\Admin\Widgets\WorkflowFailuresWidget;
use Filament\Widgets\AccountWidget;
use Livewire\Livewire;

/* M5-T04 role-aware dashboards (§8.1): widget sets per role, date filter, direct widget access. */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
});

dataset('role widget sets', [
    'owner' => [[], [KpiOverviewWidget::class, SubmissionsChartWidget::class, WorkflowFailuresWidget::class]],
    'admin' => [[SystemRole::Admin], [KpiOverviewWidget::class, SubmissionsChartWidget::class, WorkflowFailuresWidget::class]],
    'reviewer' => [[SystemRole::Reviewer], [ReviewQueueWidget::class, RecentSubmissionsWidget::class]],
    'designer' => [[SystemRole::Designer], [DraftFormsWidget::class, RecentlyPublishedWidget::class]],
    'viewer' => [[SystemRole::Viewer], [KpiOverviewWidget::class, RecentSubmissionsWidget::class]],
    'office coordinator (designer + reviewer)' => [[SystemRole::Designer, SystemRole::Reviewer],
        [ReviewQueueWidget::class, RecentSubmissionsWidget::class, DraftFormsWidget::class, RecentlyPublishedWidget::class]],
]);

it('shows the role-aware widget set', function (array $roles, array $expected): void {
    $user = $roles === [] ? $this->owner : memberOf($this->org, ...$roles);
    actingInAdmin($user, $this->org);

    $widgets = Livewire::test(Dashboard::class)->instance()->getWidgets();

    expect($widgets)->toBe([AccountWidget::class, ...$expected]);
    foreach ([KpiOverviewWidget::class, SubmissionsChartWidget::class, WorkflowFailuresWidget::class, ReviewQueueWidget::class,
        RecentSubmissionsWidget::class, DraftFormsWidget::class, RecentlyPublishedWidget::class] as $widget) {
        expect($widget::canView())->toBe(in_array($widget, $expected, true), $widget);
    }
})->with('role widget sets');

it('renders the dashboard with its widgets for every admin role', function (array $roles, array $expected): void {
    $user = $roles === [] ? $this->owner : memberOf($this->org, ...$roles);
    actingInAdmin($user, $this->org);

    $response = $this->get(Dashboard::getUrl())->assertOk();
    foreach ($expected as $widget) {
        $response->assertSeeLivewire($widget);
    }
})->with('role widget sets');

it('refuses to mount a widget the user is not offered', function (): void {
    actingInAdmin(memberOf($this->org, SystemRole::Reviewer), $this->org);

    Livewire::test(WorkflowFailuresWidget::class)->assertForbidden();
    Livewire::test(KpiOverviewWidget::class)->assertForbidden();
});

it('has a date-range filter that is handed to the widgets', function (): void {
    actingInAdmin($this->owner, $this->org);

    Livewire::test(Dashboard::class)
        ->assertSchemaComponentExists('startDate', 'filtersForm')
        ->assertSchemaComponentExists('endDate', 'filtersForm')
        ->set('filters.startDate', '2026-09-01')
        ->set('filters.endDate', '2026-09-30')
        ->assertSet('filters', ['startDate' => '2026-09-01', 'endDate' => '2026-09-30'])
        ->assertSee('01.09. – 30.09.2026');
});

it('renders every widget with its empty state when there is no data', function (): void {
    $cases = [
        [$this->owner, KpiOverviewWidget::class, __('dashboard.kpis.stale_drafts')],
        [$this->owner, SubmissionsChartWidget::class, __('dashboard.chart.heading')],
        [$this->owner, WorkflowFailuresWidget::class, __('dashboard.workflow_failures.empty')],
        [memberOf($this->org, SystemRole::Reviewer), ReviewQueueWidget::class, __('dashboard.review_queue.empty')],
        [memberOf($this->org, SystemRole::Viewer), RecentSubmissionsWidget::class, __('dashboard.recent_submissions.empty')],
        [memberOf($this->org, SystemRole::Designer), DraftFormsWidget::class, __('dashboard.draft_forms.empty')],
        [memberOf($this->org, SystemRole::Designer), RecentlyPublishedWidget::class, __('dashboard.recently_published.empty')],
    ];

    foreach ($cases as [$user, $widget, $text]) {
        actingInAdmin($user, $this->org);
        Livewire::test($widget)->assertOk()->assertSee($text);
    }
});
