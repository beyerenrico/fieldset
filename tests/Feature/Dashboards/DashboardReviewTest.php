<?php

declare(strict_types=1);

use App\Domain\Dashboards\DateRange;
use App\Domain\Identity\Enums\SystemRole;
use App\Filament\Admin\Widgets\KpiOverviewWidget;
use App\Filament\Admin\Widgets\SubmissionsChartWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Dashboards\DashboardFixtures as D;

/* Review M5: localized dashboard dates (U1), readable chart (U2), KPI definitions and freshness (U3). */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-16 10:00', 'UTC'));
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    $this->form = D::form($this->org, $this->owner);
});

afterEach(fn () => Carbon::setTestNow());

it('labels the dashboard range and chart days per locale (U1)', function (): void {
    $range = DateRange::fromFilters(['startDate' => '2026-10-11', 'endDate' => '2026-10-17'], 'Europe/Berlin');
    D::submission($this->form, $this->owner, 'submitted', D::day('2026-10-16'));
    actingInAdmin($this->owner, $this->org);

    app()->setLocale('en');
    $en = [$range->label(), D::call(Livewire::test(SubmissionsChartWidget::class), 'getData')['labels'][0]];
    app()->setLocale('de');
    $de = [$range->label(), D::call(Livewire::test(SubmissionsChartWidget::class), 'getData')['labels'][0]];

    expect($en)->toBe(['10/11 – 10/17/2026', '10/10'])->and($de)->toBe(['11.10. – 17.10.2026', '10.10.']);
});

it('shows the chart empty state and tells series apart without colour (U2)', function (): void {
    actingInAdmin($this->owner, $this->org);
    Livewire::test(SubmissionsChartWidget::class)->assertSee(__('dashboard.chart.empty'));

    D::submission($this->form, $this->owner, 'submitted', D::day('2026-10-16'));
    D::submission(D::form($this->org, $this->owner, 'delivery_note'), $this->owner, 'submitted', D::day('2026-10-15'));
    cache()->flush();   // the empty chart is cached for the TTL
    $component = Livewire::test(SubmissionsChartWidget::class);
    $datasets = D::call($component, 'getData')['datasets'];
    $alternative = (string) D::call($component, 'getChartAssistiveContent');

    expect($datasets[0]['pointStyle'])->not->toBe($datasets[1]['pointStyle'])
        ->and($datasets[0]['borderDash'])->not->toBe($datasets[1]['borderDash'])
        ->and($alternative)->toContain('<table')->toContain($this->form->name);
});

it('counts KPI submissions like reports (no drafts, no void) and explains each KPI (U3)', function (): void {
    D::submission($this->form, $this->owner, 'submitted', D::day('2026-10-16'));
    D::submission($this->form, $this->owner, 'void', D::day('2026-10-15'));
    D::submission($this->form, $this->owner, 'draft');
    actingInAdmin($this->owner, $this->org);
    app()->setLocale('de');

    $component = Livewire::test(KpiOverviewWidget::class);
    $stats = collect(D::call($component, 'getStats'))->keyBy(fn (Stat $s): string => (string) $s->getLabel());

    expect((string) $stats[__('dashboard.kpis.submissions')]->getValue())->toBe('1')
        ->and((string) $stats[__('dashboard.kpis.submissions')]->getDescription())->toContain(__('dashboard.kpis.submissions_hint'));
    $component->assertSee(__('dashboard.cached_at', ['time' => '12:00']));   // Europe/Berlin
});

it('counts only the submissions the viewer may see', function (): void {
    $reviewerForm = D::form($this->org, $this->owner, 'delivery_note');
    D::submission($reviewerForm, $this->owner, 'submitted', D::day('2026-10-16'));
    D::submission($this->form, $this->owner, 'submitted', D::day('2026-10-16'));
    $viewer = memberOf($this->org, SystemRole::Viewer);
    actingInAdmin($viewer, $this->org);

    $stats = collect(D::call(Livewire::test(KpiOverviewWidget::class), 'getStats'))->keyBy(fn (Stat $s): string => (string) $s->getLabel());

    expect((string) $stats[__('dashboard.kpis.submissions')]->getValue())->toBe('2');   // viewers see all (§8.6)
});
