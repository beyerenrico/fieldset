<?php

declare(strict_types=1);

namespace App\Domain\Reports\Scheduling;

use App\Domain\Reports\Enums\ReportSchedule;
use App\Domain\Reports\Filters\DateRange;
use Carbon\CarbonImmutable;

/**
 * When a scheduled report is due and which period it covers, in the organization's time zone:
 * weekly on Mondays at reports.schedule.hour covering the previous Monday–Sunday, monthly on the 1st
 * covering the previous calendar month.
 */
final readonly class SchedulePeriod
{
    private function __construct(public CarbonImmutable $dueAt, public DateRange $range) {}

    /** The latest due moment at or before $now (null for no schedule). */
    public static function latest(ReportSchedule $schedule, CarbonImmutable $now, string $timezone): ?self
    {
        $hour = max(0, min(23, (int) config('reports.schedule.hour', 7)));
        $local = $now->setTimezone($timezone);

        return match ($schedule) {
            ReportSchedule::Weekly => self::make($local->startOfWeek(CarbonImmutable::MONDAY), $local, $hour, 'week'),
            ReportSchedule::Monthly => self::make($local->startOfMonth(), $local, $hour, 'month'),
            ReportSchedule::None => null,
        };
    }

    private static function make(CarbonImmutable $periodStart, CarbonImmutable $local, int $hour, string $unit): self
    {
        if ($local->lessThan($periodStart->setTime($hour, 0))) {
            $periodStart = $unit === 'week' ? $periodStart->subWeek() : $periodStart->subMonthNoOverflow();
        }
        $previous = $unit === 'week' ? $periodStart->subWeek() : $periodStart->subMonthNoOverflow();

        return new self($periodStart->setTime($hour, 0)->utc(), new DateRange($previous, $periodStart));
    }
}
