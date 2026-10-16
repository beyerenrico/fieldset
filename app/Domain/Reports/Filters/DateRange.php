<?php

declare(strict_types=1);

namespace App\Domain\Reports\Filters;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * A half-open range of instants [from, until) on `submitted_at`. Calendar days are interpreted in a
 * time zone (the viewer's in the explorer, the organization's for saved reports, §11).
 */
final readonly class DateRange
{
    /** Relative periods a saved report (or a dashboard) can use. */
    public const array PERIODS = ['last_7_days', 'last_30_days', 'last_90_days', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'last_year'];

    public CarbonImmutable $from;

    public CarbonImmutable $until;

    public function __construct(CarbonImmutable $from, CarbonImmutable $until)
    {
        if ($until->lessThan($from)) {
            throw new InvalidArgumentException('The end of a date range must not be before its start.');
        }
        $this->from = $from->utc();
        $this->until = $until->utc();
    }

    /** Whole calendar days, both inclusive (Y-m-d); a missing side is open (null when both are missing). */
    public static function days(?string $fromDay, ?string $untilDay, string $timezone): ?self
    {
        $from = self::day($fromDay, $timezone);
        $until = self::day($untilDay, $timezone)?->addDay();
        if (! $from instanceof CarbonImmutable && ! $until instanceof CarbonImmutable) {
            return null;
        }

        $from ??= CarbonImmutable::createFromTimestampUTC(0);
        $until ??= CarbonImmutable::parse('9999-12-31 00:00:00', 'UTC');

        return new self($from, $until->max($from)); // "until" before "from" selects nothing instead of failing
    }

    /** The last N days including today. */
    public static function lastDays(int $days, string $timezone, ?CarbonImmutable $now = null): self
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone($timezone)->startOfDay();

        return new self($today->subDays(max(1, $days) - 1), $today->addDay());
    }

    /** One of PERIODS relative to now, or null for an unknown name. */
    public static function period(string $name, string $timezone, ?CarbonImmutable $now = null): ?self
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone($timezone);
        $week = $local->startOfWeek(CarbonImmutable::MONDAY);

        return match ($name) {
            'last_7_days' => self::lastDays(7, $timezone, $now),
            'last_30_days' => self::lastDays(30, $timezone, $now),
            'last_90_days' => self::lastDays(90, $timezone, $now),
            'this_week' => new self($week, $week->addWeek()),
            'last_week' => new self($week->subWeek(), $week),
            'this_month' => new self($local->startOfMonth(), $local->startOfMonth()->addMonth()),
            'last_month' => new self($local->startOfMonth()->subMonth(), $local->startOfMonth()),
            'this_year' => new self($local->startOfYear(), $local->startOfYear()->addYear()),
            'last_year' => new self($local->startOfYear()->subYear(), $local->startOfYear()),
            default => null,
        };
    }

    /** The overlap of two ranges (an empty range when they do not overlap). */
    public function intersect(?self $other): self
    {
        if (! $other instanceof self) {
            return $this;
        }
        $from = $this->from->max($other->from);
        $until = $this->until->min($other->until);

        return new self($from, $until->max($from));
    }

    private static function day(?string $day, string $timezone): ?CarbonImmutable
    {
        if ($day === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $day, $timezone) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
