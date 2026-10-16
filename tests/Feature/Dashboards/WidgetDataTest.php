<?php

declare(strict_types=1);

use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Enums\SystemRole;
use App\Filament\Admin\Widgets\KpiOverviewWidget;
use App\Filament\Admin\Widgets\RecentlyPublishedWidget;
use App\Filament\Admin\Widgets\RecentSubmissionsWidget;
use App\Filament\Admin\Widgets\ReviewQueueWidget;
use App\Filament\Admin\Widgets\SubmissionsChartWidget;
use App\Filament\Admin\Widgets\WorkflowFailuresWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Dashboards\DashboardFixtures as D;

/* M5-T04 widget semantics: date range, KPI definitions, chart series, review queue and visibility. */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-16 10:00', 'UTC'));
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    $this->form = D::form($this->org, $this->owner);
});

afterEach(fn () => Carbon::setTestNow());

/** @return array<string, string> label => value */
function kpiValues(array $filters = []): array
{
    $stats = D::call(Livewire::test(KpiOverviewWidget::class, ['pageFilters' => $filters]), 'getStats');

    return collect($stats)->mapWithKeys(fn (Stat $s): array => [(string) $s->getLabel() => (string) $s->getValue()])->all();
}

it('counts submissions of the last 7 days by default and of the filtered range otherwise', function (): void {
    D::submission($this->form, $this->owner, 'submitted', D::day('2026-10-16'));
    D::submission($this->form, $this->owner, 'completed', D::day('2026-10-10'));
    D::submission($this->form, $this->owner, 'completed', D::day('2026-10-09'));   // 8 days ago
    D::submission($this->form, $this->owner, 'draft');
    actingInAdmin($this->owner, $this->org);

    expect(kpiValues()[__('dashboard.kpis.submissions')])->toBe('2')
        ->and(kpiValues(['startDate' => '2026-10-01', 'endDate' => '2026-10-09'])[__('dashboard.kpis.submissions')])->toBe('1')
        ->and(kpiValues(['startDate' => '2026-10-16', 'endDate' => '2026-10-01'])[__('dashboard.kpis.submissions')])->toBe('3'); // reversed ends are swapped
});

it('counts open review tasks, stale drafts after the configured days and sent documents in the range', function (): void {
    config(['dashboards.stale_draft_days' => 10]);
    $submission = D::submission($this->form, $this->owner);
    D::task($submission);
    D::task($submission, ['status' => 'completed', 'outcome' => 'approved', 'completed_at' => now()]);
    D::task($submission, ['status' => 'cancelled']);
    D::touchedAt(D::submission($this->form, $this->owner, 'draft'), now()->subDays(11));
    D::touchedAt(D::submission($this->form, $this->owner, 'draft'), now()->subDays(9));
    D::sentMail($submission, now()->subDay());
    D::sentMail($submission, now()->subDay(), documents: false);            // notification mail
    D::sentMail($submission, now()->subDay(), status: 'failed');
    D::sentMail($submission, now()->subDays(20));                          // outside the range
    actingInAdmin($this->owner, $this->org);

    expect(kpiValues())->toBe([
        __('dashboard.kpis.submissions') => '1',
        __('dashboard.kpis.pending_reviews') => '1',
        __('dashboard.kpis.stale_drafts') => '1',
        __('dashboard.kpis.documents_sent') => '1',
    ]);
});

it('hides the documents KPI from viewers, who may not read the delivery log', function (): void {
    actingInAdmin(memberOf($this->org, SystemRole::Viewer), $this->org);

    expect(array_keys(kpiValues()))->toBe([__('dashboard.kpis.submissions'), __('dashboard.kpis.pending_reviews'), __('dashboard.kpis.stale_drafts')]);
});

it('charts one line per busiest form, sums the rest as other forms and buckets days in the viewer time zone', function (): void {
    config(['dashboards.chart_max_series' => 1]);
    $other = D::form($this->org, $this->owner, 'delivery_note');
    D::submission($this->form, $this->owner, 'submitted', Carbon::parse('2026-10-14 22:30', 'UTC')); // 15.10. in Berlin
    D::submission($this->form, $this->owner, 'submitted', D::day('2026-10-16'));
    D::submission($other, $this->owner, 'submitted', D::day('2026-10-16'));
    actingInAdmin($this->owner, $this->org);

    $data = D::call(Livewire::test(SubmissionsChartWidget::class), 'getData');

    expect($data['labels'])->toBe(['10.10.', '11.10.', '12.10.', '13.10.', '14.10.', '15.10.', '16.10.'])
        ->and($data['datasets'][0]['label'])->toBe($this->form->name)
        ->and($data['datasets'][0]['data'])->toBe([0, 0, 0, 0, 0, 1, 1])
        ->and($data['datasets'][1]['label'])->toBe(__('dashboard.chart.other_forms'))
        ->and($data['datasets'][1]['data'])->toBe([0, 0, 0, 0, 0, 0, 1])
        ->and(D::call(Livewire::test(SubmissionsChartWidget::class), 'getChartAssistiveContent'))
        ->toBe($this->form->name.': 2, '.__('dashboard.chart.other_forms').': 1');
});

it('queues only open tasks the reviewer may act on, most urgent first, and flags overdue ones', function (): void {
    $reviewer = memberOf($this->org, SystemRole::Reviewer);
    $colleague = memberOf($this->org, SystemRole::Reviewer);
    $later = D::submission($this->form, $this->owner, 'in_review', null, 'SR-LATER');
    $urgent = D::submission($this->form, $this->owner, 'in_review', null, 'SR-URGENT');
    $foreign = D::submission($this->form, $this->owner, 'in_review', null, 'SR-FOREIGN');
    D::task($later, ['assignee_user_id' => $reviewer->id, 'due_at' => now()->addDays(2)]);
    D::task($urgent, ['assignee_user_id' => $reviewer->id, 'due_at' => now()->subHour()]);
    D::task($foreign, ['assignee_user_id' => $colleague->id]);
    D::task($later, ['assignee_user_id' => $reviewer->id, 'status' => 'completed', 'outcome' => 'approved']);
    actingInAdmin($reviewer, $this->org);

    $rows = Livewire::test(ReviewQueueWidget::class)->assertSee('SR-URGENT')->assertSee(__('dashboard.review_queue.overdue'))
        ->instance()->getTable()->getRecords()->values();

    expect($rows->pluck('number')->all())->toBe(['SR-URGENT', 'SR-LATER'])
        ->and($rows->pluck('overdue')->all())->toBe([true, false]);
});

it('lists only submissions a reviewer may see (§8.6), submitted in the range', function (): void {
    $reviewer = memberOf($this->org, SystemRole::Reviewer);
    $assigned = D::submission($this->form, $this->owner, 'in_review', now()->subDay(), 'SR-ASSIGNED');
    D::submission($this->form, $this->owner, 'submitted', now()->subDay(), 'SR-HIDDEN');
    D::submission($this->form, $reviewer, 'submitted', now()->subDays(20), 'SR-OLD-OWN');
    D::task($assigned, ['assignee_user_id' => $reviewer->id]);
    actingInAdmin($reviewer, $this->org);

    $numbers = fn (array $filters): array => Livewire::test(RecentSubmissionsWidget::class, ['pageFilters' => $filters])
        ->instance()->getTable()->getRecords()->pluck('number')->all();

    expect($numbers([]))->toBe(['SR-ASSIGNED'])
        ->and($numbers(['startDate' => '2026-09-01']))->toBe(['SR-ASSIGNED', 'SR-OLD-OWN']);
});

it('limits workflow failures and published versions to the range', function (): void {
    $recent = D::failedRun($this->org, now()->subDay());
    $old = D::failedRun($this->org, now()->subDays(10));
    D::in($this->org, fn () => FormVersion::factory()->create(['form_id' => $this->form->id, 'version' => 2, 'published_at' => now()->subDays(30)]));
    actingInAdmin($this->owner, $this->org);

    $failures = fn (array $filters): array => Livewire::test(WorkflowFailuresWidget::class, ['pageFilters' => $filters])
        ->instance()->getTable()->getRecords()->pluck('id')->all();
    expect($failures([]))->toBe([$recent->id])
        ->and($failures(['startDate' => '2026-10-01']))->toBe([$recent->id, $old->id]);

    actingInAdmin(memberOf($this->org, SystemRole::Designer), $this->org);
    $versions = fn (array $filters): array => Livewire::test(RecentlyPublishedWidget::class, ['pageFilters' => $filters])
        ->instance()->getTable()->getRecords()->pluck('version')->all();
    expect($versions([]))->toBe([1])
        ->and($versions(['startDate' => '2026-09-01']))->toBe([1, 2]);
});
