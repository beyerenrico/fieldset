<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Reports\Models\SavedReport;
use App\Filament\Admin\Pages\FormExplorer;
use App\Filament\Admin\Resources\SavedReports\Pages\CreateSavedReport;
use App\Filament\Admin\Resources\SavedReports\Pages\EditSavedReport;
use App\Filament\Admin\Resources\SavedReports\Pages\ListSavedReports;
use App\Filament\Admin\Resources\SavedReports\Pages\ViewSavedReport;
use App\Filament\Admin\Resources\SavedReports\SavedReportResource;
use App\Filament\Admin\Resources\SavedReports\Widgets\SavedReportChart;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Reports\ReportsScenario;

/* M5-T03 saved reports in the admin: create, view as table + chart, share, CSV, save from the explorer. */

beforeEach(function (): void {
    $this->s = ReportsScenario::make();
    $s = $this->s;
    $s->submit($s->worker, ['amount' => '0.10', 'kind' => 'repair'], submittedAt: '2026-10-01 10:00');
    $s->submit($s->worker, ['amount' => '0.20', 'kind' => 'repair'], submittedAt: '2026-10-02 10:00');
    $s->submit($s->worker, ['amount' => '5', 'kind' => 'maintenance'], submittedAt: '2026-10-03 10:00');
});

it('creates a report from the form with group-by, metric and field filters', function (): void {
    actingInAdmin($this->s->owner, $this->s->org);

    Livewire::test(CreateSavedReport::class)
        ->fillForm([
            'name' => 'Revenue by kind', 'form_id' => $this->s->form->id, 'group_by' => 'field:kind', 'metric' => 'sum',
            'metric_key' => 'amount', 'visibility' => 'organization', 'schedule' => 'none',
            'filters' => ['period' => null, 'statuses' => ['submitted'], 'groups' => []],
            'field_filters' => [['key' => 'amount', 'min' => '0', 'max' => '1']],
        ])
        ->call('create')->assertHasNoFormErrors();

    $report = app(TenantContext::class)->run($this->s->org, fn () => SavedReport::query()->sole());
    expect($report->group_by)->toBe('field:kind')->and($report->metric_key)->toBe('amount')
        ->and($report->filters['statuses'])->toBe(['submitted'])
        ->and($report->filters['fields'])->toEqual([['key' => 'amount', 'operator' => 'between', 'values' => ['0', '1']]]);
});

it('refuses a sum without a number field', function (): void {
    actingInAdmin($this->s->owner, $this->s->org);

    Livewire::test(CreateSavedReport::class)
        ->fillForm(['name' => 'Broken', 'form_id' => $this->s->form->id, 'metric' => 'sum', 'metric_key' => null, 'visibility' => 'private', 'schedule' => 'none'])
        ->call('create')->assertHasFormErrors(['metric_key' => 'required']);
});

it('shows the result as a table and a chart with exact decimal values', function (): void {
    $report = app(TenantContext::class)->run($this->s->org, fn () => SavedReport::factory()->create([
        'form_id' => $this->s->form->id, 'name' => 'Revenue', 'group_by' => 'field:kind', 'metric' => 'sum', 'metric_key' => 'amount', 'created_by' => $this->s->owner->id,
    ]));
    actingInAdmin($this->s->owner, $this->s->org);

    Livewire::test(ViewSavedReport::class, ['record' => $report->id])->assertOk()
        ->assertSeeInOrder(['Repair', '0.30 EUR', 'Maintenance', '5.00 EUR', 'Total', '5.30 EUR']);   // most submissions first
    Livewire::test(SavedReportChart::class, ['record' => $report])->assertOk()->assertSee('0.3');
});

it('downloads the result as CSV', function (): void {
    $report = app(TenantContext::class)->run($this->s->org, fn () => SavedReport::factory()->create([
        'form_id' => $this->s->form->id, 'name' => 'Revenue', 'group_by' => 'field:kind', 'metric' => 'sum', 'metric_key' => 'amount', 'created_by' => $this->s->owner->id,
    ]));
    actingInAdmin($this->s->owner, $this->s->org);

    Livewire::test(ViewSavedReport::class, ['record' => $report->id])->callAction('downloadCsv')->assertFileDownloaded('revenue.csv');
});

it('lists own and shared reports but not the private reports of others', function (): void {
    $designer = memberOf($this->s->org, SystemRole::Designer);
    [$shared, $mine, $theirs] = app(TenantContext::class)->run($this->s->org, fn (): array => [
        SavedReport::factory()->create(['form_id' => $this->s->form->id, 'visibility' => 'organization', 'created_by' => $this->s->owner->id]),
        SavedReport::factory()->create(['form_id' => $this->s->form->id, 'visibility' => 'private', 'created_by' => $designer->id]),
        SavedReport::factory()->create(['form_id' => $this->s->form->id, 'visibility' => 'private', 'created_by' => $this->s->owner->id]),
    ]);
    actingInAdmin($designer, $this->s->org);

    Livewire::test(ListSavedReports::class)->assertCanSeeTableRecords([$shared, $mine])->assertCanNotSeeTableRecords([$theirs]);
    $this->get(SavedReportResource::getUrl('edit', ['record' => $shared]))->assertForbidden();
    Livewire::test(EditSavedReport::class, ['record' => $mine->id])->assertOk();
});

it('saves the explorer filters as a report', function (): void {
    actingInAdmin($this->s->owner, $this->s->org);

    Livewire::withQueryParams(['form' => $this->s->form->id])->test(FormExplorer::class)
        ->filterTable('field_kind', ['repair'])
        ->callAction('saveReport', ['name' => 'Repairs', 'visibility' => 'organization'])
        ->assertRedirect();

    $report = app(TenantContext::class)->run($this->s->org, fn () => SavedReport::query()->sole());
    expect($report->name)->toBe('Repairs')->and($report->filters['fields'])->toEqual([['key' => 'kind', 'operator' => 'in', 'values' => ['repair']]]);
});

it('keeps every explorer filter through save as report, edit and save (review H1)', function (): void {
    $s = $this->s;
    actingInAdmin($s->owner, $s->org);
    Livewire::withQueryParams(['form' => $s->form->id])->test(FormExplorer::class)
        ->filterTable('submitted', ['from' => '2026-10-01', 'until' => '2026-10-02'])
        ->filterTable('submitter', [$s->worker->id])
        ->filterTable('field_kind', ['repair'])
        ->filterTable('field_amount', ['min' => '0.1', 'max' => '1'])
        ->searchTable('Walter')
        ->callAction('saveReport', ['name' => 'Kept', 'visibility' => 'private'])
        ->assertRedirect();

    $report = app(TenantContext::class)->run($s->org, fn () => SavedReport::query()->sole());
    $stored = $report->filters;
    expect($stored)->toMatchArray([
        'from' => '2026-10-01', 'until' => '2026-10-02', 'submitters' => [$s->worker->id], 'search' => 'Walter',
        'timezone' => SubmissionResource::displayTimezone(),
    ])->and($stored['fields'])->toEqual([
        ['key' => 'amount', 'operator' => 'between', 'values' => ['0.1', '1']],
        ['key' => 'kind', 'operator' => 'in', 'values' => ['repair']],
    ]);

    Livewire::test(EditSavedReport::class, ['record' => $report->id])
        ->assertFormSet([
            'filters.period' => 'custom', 'filters.from' => '2026-10-01', 'filters.until' => '2026-10-02',
            'filters.submitters' => [$s->worker->id], 'filters.search' => 'Walter',
        ])
        ->assertSee(__('reports.period.custom'))
        ->call('save')->assertHasNoFormErrors();

    expect($report->refresh()->filters)->toEqual($stored);
});

it('edits field filters with typed inputs per field type (review H2)', function (): void {
    actingInAdmin($this->s->owner, $this->s->org);

    Livewire::test(CreateSavedReport::class)
        ->fillForm([
            'name' => 'Typed', 'form_id' => $this->s->form->id, 'metric' => 'count', 'visibility' => 'private', 'schedule' => 'none',
            'filters' => ['period' => 'custom', 'from' => '2026-10-01', 'until' => null],
            'field_filters' => [
                ['key' => 'kind', 'values' => ['repair', 'maintenance']],
                ['key' => 'done', 'bool' => 'false'],
                ['key' => 'visit_date', 'from' => '2026-10-01', 'until' => '2026-10-31'],
                ['key' => 'amount', 'min' => '1,5', 'max' => null],
            ],
        ])
        ->assertSee(__('reports.saved.add_field_filter'))
        ->call('create')->assertHasNoFormErrors();

    $report = app(TenantContext::class)->run($this->s->org, fn () => SavedReport::query()->sole());
    expect($report->filters['from'])->toBe('2026-10-01')->and($report->filters['period'])->toBeNull()
        ->and($report->filters['fields'])->toEqual([
            ['key' => 'kind', 'operator' => 'in', 'values' => ['repair', 'maintenance']],
            ['key' => 'done', 'operator' => 'is', 'values' => [false]],
            ['key' => 'visit_date', 'operator' => 'between', 'values' => ['2026-10-01', '2026-10-31']],
            ['key' => 'amount', 'operator' => 'between', 'values' => ['1.5', null]],
        ]);
});

it('rejects invalid or empty field filter values instead of dropping them (review H2)', function (): void {
    actingInAdmin($this->s->owner, $this->s->org);
    $base = ['name' => 'Broken', 'form_id' => $this->s->form->id, 'metric' => 'count', 'visibility' => 'private', 'schedule' => 'none'];

    Livewire::test(CreateSavedReport::class)
        ->fillForm([...$base, 'field_filters' => [['key' => 'amount', 'min' => 'abc', 'max' => null]]])
        ->call('create')->assertHasFormErrors(['field_filters.0.min']);
    Livewire::test(CreateSavedReport::class)
        ->fillForm([...$base, 'field_filters' => [['key' => 'kind', 'values' => []]]])
        ->call('create')->assertHasFormErrors(['field_filters.0.values']);
    Livewire::test(CreateSavedReport::class)
        ->fillForm([...$base, 'field_filters' => [['key' => 'visit_date', 'from' => null, 'until' => null]]])
        ->call('create')->assertHasFormErrors(['field_filters.0.from']);
    Livewire::test(CreateSavedReport::class)
        ->fillForm([...$base, 'filters' => ['period' => 'custom', 'from' => null, 'until' => null]])
        ->call('create')->assertHasFormErrors(['filters.from']);

    expect(app(TenantContext::class)->run($this->s->org, fn () => SavedReport::query()->count()))->toBe(0);
});

it('runs the report once per page view and renders the chart from that result (M8-T05)', function (): void {
    $report = app(TenantContext::class)->run($this->s->org, fn () => SavedReport::factory()->create([
        'form_id' => $this->s->form->id, 'name' => 'Revenue', 'group_by' => 'field:kind', 'metric' => 'sum', 'metric_key' => 'amount', 'created_by' => $this->s->owner->id,
    ]));
    actingInAdmin($this->s->owner, $this->s->org);

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ViewSavedReport::class, ['record' => $report->id])->assertOk()->assertSeeLivewire(SavedReportChart::class);
    $aggregates = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'count(distinct s.uuid)'));

    expect($aggregates)->toHaveCount(1);
});
