<?php

declare(strict_types=1);

use App\Domain\Dashboards\DateRange;
use Illuminate\Support\Carbon;

/* M5-T04 dashboard date filter: whole local days, UTC bounds, defaults, swapped and capped ranges. */

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-16 23:30', 'UTC'))); // already 17.10. in Berlin
afterEach(fn () => Carbon::setTestNow());

it('defaults to the last 7 local days including today', function (): void {
    app()->setLocale('de');   // labels follow the locale (LocalDate); en: 10/11 – 10/17/2026
    $range = DateRange::fromFilters(null, 'Europe/Berlin');

    expect($range->start->toDateString())->toBe('2026-10-11')
        ->and($range->end->toDateString())->toBe('2026-10-17')
        ->and($range->days())->toHaveCount(7)
        ->and($range->label())->toBe('11.10. – 17.10.2026');
});

it('turns local whole days into UTC bounds', function (): void {
    app()->setLocale('de');
    $range = DateRange::fromFilters(['startDate' => '2026-10-01', 'endDate' => '2026-10-01'], 'Europe/Berlin');

    expect($range->startUtc()->toDateTimeString())->toBe('2026-09-30 22:00:00')
        ->and($range->endUtc()->toDateTimeString())->toBe('2026-10-01 22:00:00')
        ->and($range->label())->toBe('01.10.2026');
});

it('swaps reversed ends, ignores invalid input and caps long ranges', function (): void {
    config(['dashboards.max_range_days' => 30]);

    $swapped = DateRange::fromFilters(['startDate' => '2026-10-10', 'endDate' => '2026-10-01'], 'UTC');
    $invalid = DateRange::fromFilters(['startDate' => 'yesterday', 'endDate' => ['2026-10-01']], 'UTC');
    $capped = DateRange::fromFilters(['startDate' => '2025-01-01', 'endDate' => '2026-10-10'], 'UTC');

    expect([$swapped->start->toDateString(), $swapped->end->toDateString()])->toBe(['2026-10-01', '2026-10-10'])
        ->and([$invalid->start->toDateString(), $invalid->end->toDateString()])->toBe(['2026-10-10', '2026-10-16'])
        ->and([$capped->start->toDateString(), $capped->end->toDateString()])->toBe(['2026-09-11', '2026-10-10'])
        ->and($capped->days())->toHaveCount(30);
});
