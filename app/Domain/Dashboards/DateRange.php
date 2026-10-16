<?php

declare(strict_types=1);

namespace App\Domain\Dashboards;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The dashboard date filter (§8.1): whole days in the viewer's time zone, both ends inclusive.
 * Queries use the UTC bounds [startUtc, endUtc). Empty or invalid input falls back to the last
 * `dashboards.default_range_days` days; reversed ends are swapped; long ranges are capped.
 */
final readonly class DateRange
{
    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $timezone,
    ) {}

    /** @param  array<string, mixed>|null  $filters  dashboard filters (`startDate`, `endDate`, Y-m-d) */
    public static function fromFilters(?array $filters, string $timezone): self
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $days = max(1, (int) config('dashboards.default_range_days', 7));
        $end = self::parse($filters['endDate'] ?? null, $timezone) ?? $today;
        $start = self::parse($filters['startDate'] ?? null, $timezone) ?? $end->subDays($days - 1);

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }
        $max = max(1, (int) config('dashboards.max_range_days', 366));
        if ($start->diffInDays($end) >= $max) {
            $start = $end->subDays($max - 1);
        }

        return new self($start, $end, $timezone);
    }

    public static function lastDays(int $days, string $timezone): self
    {
        $end = CarbonImmutable::now($timezone)->startOfDay();

        return new self($end->subDays(max(1, $days) - 1), $end, $timezone);
    }

    /** Inclusive lower bound in UTC. */
    public function startUtc(): CarbonImmutable
    {
        return $this->start->startOfDay()->utc();
    }

    /** Exclusive upper bound in UTC (start of the day after the end date). */
    public function endUtc(): CarbonImmutable
    {
        return $this->end->addDay()->startOfDay()->utc();
    }

    /** @return list<string> each day of the range as Y-m-d (viewer time zone) */
    public function days(): array
    {
        $days = [];
        for ($day = $this->start; $day->lessThanOrEqualTo($this->end); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    public function label(): string
    {
        return $this->start->isSameDay($this->end)
            ? $this->start->format('d.m.Y')
            : $this->start->format('d.m.').' – '.$this->end->format('d.m.Y');
    }

    public function cacheKey(): string
    {
        return $this->start->toDateString().'_'.$this->end->toDateString().'_'.$this->timezone;
    }

    private static function parse(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', substr($value, 0, 10), $timezone)?->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
