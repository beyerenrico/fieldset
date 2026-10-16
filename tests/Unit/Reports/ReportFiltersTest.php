<?php

declare(strict_types=1);

use App\Domain\Reports\Enums\ReportSchedule;
use App\Domain\Reports\Filters\DateRange;
use App\Domain\Reports\Filters\FieldFilter;
use App\Domain\Reports\Filters\ReportFilters;
use App\Domain\Reports\Scheduling\SchedulePeriod;
use Carbon\CarbonImmutable;

/* M5 report filters: normalization of untrusted filter input, date ranges in a time zone, schedules. */

it('normalizes untrusted filters and round-trips them', function (): void {
    $filters = ReportFilters::fromArray([
        'from' => '2026-10-01', 'until' => 'yesterday', 'period' => 'next_century',
        'statuses' => ['submitted', 'draft', 'nonsense', 'submitted'], 'submitters' => ['3', 4, 'x', -1], 'groups' => [7],
        'fields' => [
            ['key' => 'kind', 'operator' => 'in', 'values' => ['repair', ['x'], 'repair']],
            ['key' => 'amount', 'operator' => 'between', 'values' => ['10.5', 'abc']],
            ['key' => 'done', 'operator' => 'is', 'values' => [false]],
            ['key' => 'DROP TABLE', 'operator' => 'in', 'values' => ['x']],
            ['key' => 'kind', 'operator' => 'like', 'values' => ['%']],
        ],
        'search' => '  Müller  ',
    ]);

    expect($filters->from)->toBe('2026-10-01')->and($filters->until)->toBeNull()->and($filters->period)->toBeNull()
        ->and($filters->statuses)->toBe(['submitted'])
        ->and($filters->submitters)->toBe([3, 4])
        ->and(array_map(fn (FieldFilter $f): array => $f->toArray(), $filters->fields))->toBe([
            ['key' => 'kind', 'operator' => 'in', 'values' => ['repair']],
            ['key' => 'amount', 'operator' => 'between', 'values' => ['10.5', null]],
            ['key' => 'done', 'operator' => 'is', 'values' => [false]],
        ])
        ->and($filters->search)->toBe('Müller')
        ->and(ReportFilters::fromArray($filters->toArray()))->toEqual($filters);
});

it('excludes draft and void unless statuses are chosen', function (): void {
    expect((new ReportFilters)->effectiveStatuses())->toBe(['submitted', 'in_review', 'changes_requested', 'approved', 'completed'])
        ->and(new ReportFilters(statuses: ['void'])->effectiveStatuses())->toBe(['void']);
});

it('interprets calendar days and periods in the time zone', function (): void {
    $now = CarbonImmutable::parse('2026-10-14 12:00', 'UTC');   // a Wednesday

    $days = DateRange::days('2026-10-01', '2026-10-31', 'Europe/Berlin');
    $lastMonth = DateRange::period('last_month', 'Europe/Berlin', $now);
    $lastWeek = DateRange::period('last_week', 'Europe/Berlin', $now);

    expect($days?->from->toIso8601String())->toBe('2026-09-30T22:00:00+00:00')
        ->and($days?->until->toIso8601String())->toBe('2026-10-31T23:00:00+00:00')   // DST ends on 25 October
        ->and($lastMonth?->from->toIso8601String())->toBe('2026-08-31T22:00:00+00:00')
        ->and($lastWeek?->from->toIso8601String())->toBe('2026-10-04T22:00:00+00:00')
        ->and(DateRange::days(null, null, 'UTC'))->toBeNull()
        ->and(DateRange::days('2026-10-05', '2026-10-01', 'UTC')?->from->equalTo(DateRange::days('2026-10-05', '2026-10-01', 'UTC')->until))->toBeTrue()
        ->and(DateRange::period('unknown', 'UTC'))->toBeNull()
        ->and($days?->intersect($lastWeek)->from->equalTo($lastWeek?->from))->toBeTrue();
});

it('finds the latest due moment of weekly and monthly schedules', function (): void {
    $weekly = SchedulePeriod::latest(ReportSchedule::Weekly, CarbonImmutable::parse('2026-10-14 12:00', 'UTC'), 'Europe/Berlin');
    $beforeSeven = SchedulePeriod::latest(ReportSchedule::Weekly, CarbonImmutable::parse('2026-10-12 06:30', 'Europe/Berlin'), 'Europe/Berlin');
    $monthly = SchedulePeriod::latest(ReportSchedule::Monthly, CarbonImmutable::parse('2026-11-01 07:00', 'Europe/Berlin'), 'Europe/Berlin');

    expect($weekly?->dueAt->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-12 07:00')
        ->and($weekly?->range->from->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-05 00:00')
        ->and($beforeSeven?->dueAt->setTimezone('Europe/Berlin')->format('Y-m-d'))->toBe('2026-10-05')
        ->and($monthly?->range->from->setTimezone('Europe/Berlin')->format('Y-m-d'))->toBe('2026-10-01')
        ->and($monthly?->range->until->setTimezone('Europe/Berlin')->format('Y-m-d'))->toBe('2026-11-01')
        ->and(SchedulePeriod::latest(ReportSchedule::None, CarbonImmutable::now(), 'UTC'))->toBeNull();
});
