<?php

declare(strict_types=1);

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Reports\Enums\ReportMetric;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\Saved\ReportCsv;
use App\Domain\Reports\Saved\ReportResult;
use App\Domain\Reports\Saved\ReportRow;
use App\Domain\Reports\SavedReportQuery;
use App\Domain\Submissions\Admin\ValueFormatter;
use App\Filament\Admin\Resources\SavedReports\Pages\ViewSavedReport;
use Livewire\Livewire;
use Tests\Feature\Reports\ReportsScenario;

/* Review M5: money per version (M1), bounded groups (M6), localized period labels (U1), CSV (U8). */

/** @param  array<string, mixed>  $attributes */
function reviewReport(ReportsScenario $s, array $attributes): SavedReport
{
    return app(TenantContext::class)->run($s->org, fn (): SavedReport => SavedReport::query()->create([
        'form_id' => $s->form->id, 'name' => 'Report', 'filters' => [], 'created_by' => $s->owner->id, 'visibility' => 'organization', ...$attributes,
    ]));
}

function reviewRun(ReportsScenario $s, SavedReport $report): ReportResult
{
    return app(TenantContext::class)->run($s->org, fn (): ReportResult => app(SavedReportQuery::class)->run($report, null, $s->owner));
}

it('sums money per currency where a group has one and refuses groups that mix currencies', function (): void {
    $s = ReportsScenario::make([1 => ['currency' => 'JPY', 'scale' => 0]]);
    $s->submit($s->worker, ['amount' => '1200', 'kind' => 'repair'], version: 1);
    $s->submit($s->worker, ['amount' => '10.50', 'kind' => 'repair'], version: 3);
    $s->submit($s->worker, ['amount' => '5.25', 'kind' => 'maintenance'], version: 3);
    $s->submit($s->worker, ['kind' => 'maintenance'], version: 1);   // no amount: does not make the group mixed

    $byVersion = reviewRun($s, reviewReport($s, ['metric' => 'sum', 'metric_key' => 'amount', 'group_by' => 'version']));
    $byKind = reviewRun($s, reviewReport($s, ['metric' => 'sum', 'metric_key' => 'amount', 'group_by' => 'field:kind']));
    $en = new ValueFormatter('en', 'UTC');
    $rows = collect($byKind->rows)->keyBy('label');

    expect(collect($byVersion->rows)->map(fn (ReportRow $r): array => [$r->label, $r->sum, $r->currency, $r->mixed])->all())
        ->toBe([['v1', '1200', 'JPY', false], ['v3', '15.75', 'EUR', false]])
        ->and($byVersion->displayValue($byVersion->rows[0], $en))->toBe('1,200 JPY')
        ->and($byVersion->displayValue($byVersion->rows[1], $en))->toBe('15.75 EUR')
        ->and($byVersion->total->mixed)->toBeTrue()
        ->and($byVersion->total->value($byVersion->metric))->toBeNull()
        ->and($byVersion->mixedCurrencies())->toBe(['EUR', 'JPY'])   // sorted
        ->and($rows['Repair']->mixed)->toBeTrue()
        ->and($rows['Maintenance']->sum)->toBe('5.25')
        ->and($byKind->displayValue($rows['Repair'], $en))->toContain('—');

    $report = reviewReport($s, ['metric' => 'sum', 'metric_key' => 'amount', 'group_by' => 'version']);
    actingInAdmin($s->owner, $s->org);
    Livewire::test(ViewSavedReport::class, ['record' => $report->id])
        ->assertSeeInOrder(['1,200 JPY', '15.75 EUR'])
        ->assertSee(__('reports.result.mixed_currencies', ['currencies' => 'EUR, JPY']));
});

it('pads money to the scale of the field (JPY 0, scale 3)', function (): void {
    $s = ReportsScenario::make([3 => ['currency' => 'EUR', 'scale' => 3]]);
    $s->submit($s->worker, ['amount' => '5.125'], version: 3);
    $s->submit($s->worker, ['amount' => '0.875'], version: 3);

    $result = reviewRun($s, reviewReport($s, ['metric' => 'sum', 'metric_key' => 'amount']));

    expect($result->total->sum)->toBe('6')
        ->and($result->displayValue($result->total, new ValueFormatter('en', 'UTC')))->toBe('6.000 EUR');
});

it('caps the groups of a report and flags the result as truncated', function (): void {
    config(['reports.saved.max_groups' => 2]);
    $s = ReportsScenario::make();
    foreach (['A', 'B', 'C'] as $i => $name) {
        $s->submit($s->worker, ['customer' => $name], submittedAt: '2026-10-0'.($i + 1).' 10:00');
    }

    $byCustomer = reviewRun($s, reviewReport($s, ['group_by' => 'field:customer']));
    $byDay = reviewRun($s, reviewReport($s, ['group_by' => 'day']));
    $report = reviewReport($s, ['group_by' => 'field:customer']);
    actingInAdmin($s->owner, $s->org);

    expect(count($byCustomer->rows))->toBe(2)->and($byCustomer->truncated)->toBeTrue()
        ->and($byCustomer->total->submissions)->toBe(3)
        ->and(array_map(fn (ReportRow $r): ?string => $r->key, $byDay->rows))->toBe(['2026-10-02', '2026-10-03'])   // newest days kept
        ->and(ReportCsv::make($byCustomer))->toContain(__('reports.result.truncated', ['count' => 2]));
    Livewire::test(ViewSavedReport::class, ['record' => $report->id])->assertSee(__('reports.result.truncated', ['count' => 2]));
});

it('labels day, week and month groups in the viewer locale', function (): void {
    $s = ReportsScenario::make();
    $s->submit($s->worker, ['kind' => 'repair'], submittedAt: '2026-10-06 10:00');

    $labels = function (string $groupBy, string $locale) use ($s): string {
        app()->setLocale($locale);

        return reviewRun($s, reviewReport($s, ['group_by' => $groupBy]))->rows[0]->label;
    };

    expect($labels('day', 'en'))->toBe('10/06/2026')->and($labels('day', 'de'))->toBe('06.10.2026')
        ->and($labels('week', 'en'))->toBe('Week 41 2026')->and($labels('week', 'de'))->toBe('KW 41 2026')
        ->and($labels('month', 'en'))->toBe('October 2026')->and($labels('month', 'de'))->toBe('Oktober 2026');
});

it('writes a German CSV with semicolons and decimal commas and neutralizes formula labels', function (): void {
    $s = ReportsScenario::make();
    $s->submit($s->worker, ['amount' => '1234.5', 'kind' => 'repair']);
    app(TenantContext::class)->run($s->org, fn () => null);
    $report = reviewReport($s, ['metric' => 'sum', 'metric_key' => 'amount', 'group_by' => 'field:kind']);

    app()->setLocale('de');
    $german = ReportCsv::make(reviewRun($s, $report));
    app()->setLocale('en');
    $english = ReportCsv::make(reviewRun($s, $report));
    $formula = ReportCsv::make(new ReportResult(ReportMetric::Count, null, 'n', 'field:x', '=HYPERLINK("x")', [], new ReportRow(null, 'Total', 0)));

    expect($german)->toContain('Repair;1;1234,5')->and($german)->toContain('Gesamt;1;1234,5')
        ->and($english)->toContain('Repair,1,1234.5')
        ->and($formula)->toContain("'=HYPERLINK");
});
