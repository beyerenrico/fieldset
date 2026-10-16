<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Reports\Filters\DateRange;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\Saved\ReportResult;
use App\Domain\Reports\Saved\ReportRow;
use App\Domain\Reports\SavedReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Feature\Reports\ReportsScenario;

/* M5-T03 saved reports: count/sum/avg in SQL, decimal-exact, grouped, per viewer visibility. */

beforeEach(function (): void {
    $this->s = ReportsScenario::make();
});

/** @param  array<string, mixed>  $attributes */
function savedReport(ReportsScenario $s, array $attributes, ?User $creator = null): SavedReport
{
    return app(TenantContext::class)->run($s->org, fn (): SavedReport => SavedReport::query()->create([
        'form_id' => $s->form->id, 'name' => 'Report', 'filters' => [], 'created_by' => ($creator ?? $s->owner)->id, 'visibility' => 'organization', ...$attributes,
    ]));
}

function runReport(ReportsScenario $s, SavedReport $report, ?User $viewer = null, ?DateRange $range = null): ReportResult
{
    return app(TenantContext::class)->run($s->org, fn (): ReportResult => app(SavedReportQuery::class)->run($report, $range, $viewer ?? $s->owner));
}

/** @return array<string, array{int, string|null}> label => [submissions, value] */
function resultRows(ReportResult $result): array
{
    return collect($result->rows)->mapWithKeys(fn (ReportRow $r): array => [$r->label => [$r->submissions, $r->value($result->metric)]])->all();
}

it('sums money decimal-exact in SQL (0.1 + 0.2 style values over many rows)', function (): void {
    $s = $this->s;
    foreach (range(1, 30) as $i) {
        $s->submit($s->worker, ['amount' => $i % 2 === 0 ? '0.1' : '0.2', 'kind' => $i % 3 === 0 ? 'maintenance' : 'repair']);
    }
    $s->submit($s->worker, ['amount' => '1234567890123456789.0000000001', 'kind' => 'repair']);

    $sum = runReport($s, savedReport($s, ['metric' => 'sum', 'metric_key' => 'amount', 'group_by' => 'field:kind']));
    $avg = runReport($s, savedReport($s, ['metric' => 'avg', 'metric_key' => 'amount']));

    expect(resultRows($sum))->toBe([
        'Repair' => [21, '1234567890123456792.0000000001'],  // 10 × 0.1 + 10 × 0.2 + the large value
        'Maintenance' => [10, '1.5'],                          // 5 × 0.1 + 5 × 0.2
    ])
        ->and($sum->total->sum)->toBe('1234567890123456793.5000000001')
        ->and($sum->isMoney)->toBeTrue()->and($sum->currency)->toBe('EUR')
        ->and($avg->rows[0]->avg)->toBe('39824770649143767.5322580645')
        ->and($sum->chart()['values'])->toBe(['1234567890123456792.0000000001', '1.5']);
});

it('sums a repeater field over all rows and counts submissions once', function (): void {
    $s = $this->s;
    $s->submit($s->worker, ['kind' => 'repair', 'items' => [['qty' => '1.5'], ['qty' => '2.25']]], version: 2);
    $s->submit($s->worker, ['kind' => 'repair', 'items' => [['qty' => '0.25']]], version: 2);

    $result = runReport($s, savedReport($s, ['metric' => 'sum', 'metric_key' => 'items.qty', 'group_by' => 'field:kind']));

    expect(resultRows($result))->toBe(['Repair' => [2, '4']]);
});

it('groups by status, submitter, version and month in the organization time zone', function (): void {
    $s = $this->s;
    $s->submit($s->worker, ['kind' => 'repair'], version: 1, submittedAt: '2026-09-30 22:30');   // 1 Oct 00:30 in Berlin
    $s->submit($s->worker, ['kind' => 'repair'], version: 3, status: 'approved', submittedAt: '2026-10-15 10:00');
    $s->submit($s->owner, ['kind' => 'repair'], version: 3, submittedAt: '2026-11-02 10:00');

    expect(resultRows(runReport($s, savedReport($s, ['group_by' => 'month']))))->toBe(['2026-10' => [2, '2'], '2026-11' => [1, '1']])
        ->and(resultRows(runReport($s, savedReport($s, ['group_by' => 'status']))))->toBe(['Submitted' => [2, '2'], 'Approved' => [1, '1']])
        ->and(resultRows(runReport($s, savedReport($s, ['group_by' => 'submitter']))))->toBe(['Walter Worker' => [2, '2'], $s->owner->name => [1, '1']])
        ->and(resultRows(runReport($s, savedReport($s, ['group_by' => 'version']))))->toBe(['v3' => [2, '2'], 'v1' => [1, '1']]);
});

it('applies the saved filters and narrows them with a date range', function (): void {
    $s = $this->s;
    $s->submit($s->worker, ['kind' => 'repair', 'amount' => '10'], submittedAt: '2026-10-01 10:00');
    $s->submit($s->worker, ['kind' => 'maintenance', 'amount' => '20'], submittedAt: '2026-10-05 10:00');
    $s->submit($s->worker, ['kind' => 'repair', 'amount' => '30'], submittedAt: '2026-10-09 10:00');
    $s->submit($s->worker, ['kind' => 'repair', 'amount' => '40'], status: 'void', submittedAt: '2026-10-09 11:00');
    $report = savedReport($s, ['metric' => 'sum', 'metric_key' => 'amount', 'filters' => [
        'fields' => [['key' => 'kind', 'operator' => 'in', 'values' => ['repair']]],
    ]]);
    $range = new DateRange(CarbonImmutable::parse('2026-10-08 00:00', 'UTC'), CarbonImmutable::parse('2026-10-10 00:00', 'UTC'));

    expect(runReport($s, $report)->total->sum)->toBe('40')            // void excluded by default
        ->and(runReport($s, $report, range: $range)->total->sum)->toBe('30');
});

it('computes the numbers each viewer may see and enforces report visibility', function (): void {
    $s = $this->s;
    $reviewer = memberOf($s->org, SystemRole::Reviewer);
    $s->group('Crew', $reviewer, $s->worker);
    $s->submit($s->worker, ['amount' => '1']);
    $s->submit($s->owner, ['amount' => '2']);
    $shared = savedReport($s, ['metric' => 'sum', 'metric_key' => 'amount']);
    $private = savedReport($s, ['visibility' => 'private']);

    expect(runReport($s, $shared)->total->sum)->toBe('3')
        ->and(runReport($s, $shared, $reviewer)->total->sum)->toBe('1')
        ->and(fn () => runReport($s, $private, $reviewer))->toThrow(AuthorizationException::class)
        ->and(fn () => runReport($s, $shared, $s->worker))->toThrow(AuthorizationException::class);
});

it('falls back to counting when the metric field is not numeric', function (): void {
    $this->s->submit($this->s->worker, ['customer' => 'A']);

    $result = runReport($this->s, savedReport($this->s, ['metric' => 'sum', 'metric_key' => 'customer']));

    expect($result->metric->value)->toBe('count')->and($result->total->submissions)->toBe(1);
});
