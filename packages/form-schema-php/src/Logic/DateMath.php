<?php

declare(strict_types=1);

namespace FormSchema\Logic;

use Brick\Math\RoundingMode;
use FormSchema\Json\JsNumber;

/**
 * Calendar arithmetic for date.diff and date.add (§5.3) on the proleptic Gregorian calendar.
 */
final class DateMath
{
    private const DATE_UNITS = ['days', 'weeks', 'months', 'years'];

    private const DATE_TIME_UNITS = ['minutes' => 60, 'hours' => 3600, 'days' => 86400];

    /** Larger amounts always leave the representable year range 0000–9999. */
    private const MAX_AMOUNT = 10_000_000_000;

    /**
     * Integer `to − from` in the unit, truncated toward zero; null for mixed or invalid input.
     */
    public static function diff(mixed $from, mixed $to, mixed $unit): ?int
    {
        $fromDate = DateValue::parseDate($from);
        $toDate = DateValue::parseDate($to);

        if ($fromDate !== null && $toDate !== null) {
            return self::dateDiff($fromDate, $toDate, $unit);
        }

        $fromTime = DateValue::parseDateTime($from);
        $toTime = DateValue::parseDateTime($to);

        if ($fromTime === null || $toTime === null || ! is_string($unit)) {
            return null;
        }

        $perUnit = self::DATE_TIME_UNITS[$unit] ?? null;

        if ($perUnit === null) {
            return null;
        }

        return $toTime->instant()->minus($fromTime->instant())
            ->dividedBy($perUnit, 0, RoundingMode::Down)
            ->toInt();
    }

    /**
     * Date (or date-time keeping its offset) plus an integer amount of the unit.
     */
    public static function add(mixed $value, mixed $amount, mixed $unit): ?string
    {
        $steps = self::amount($amount);

        if ($steps === null || ! is_string($unit)) {
            return null;
        }

        $date = DateValue::parseDate($value);

        if ($date !== null) {
            return self::addToDate($date, $steps, $unit);
        }

        $dateTime = DateValue::parseDateTime($value);

        return $dateTime === null ? null : self::addToDateTime($dateTime, $steps, $unit);
    }

    public static function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    public static function daysInMonth(int $year, int $month): int
    {
        return match ($month) {
            2 => self::isLeapYear($year) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }

    /**
     * Days since 1970-01-01 (H. Hinnant's days_from_civil).
     */
    public static function daysFromCivil(int $year, int $month, int $day): int
    {
        $y = $month <= 2 ? $year - 1 : $year;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yearOfEra = $y - $era * 400;
        $dayOfYear = intdiv(153 * ($month > 2 ? $month - 3 : $month + 9) + 2, 5) + $day - 1;
        $dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;

        return $era * 146097 + $dayOfEra - 719468;
    }

    /**
     * Inverse of daysFromCivil().
     *
     * @return array{int, int, int} year, month, day
     */
    public static function civilFromDays(int $days): array
    {
        $z = $days + 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $dayOfEra = $z - $era * 146097;
        $yearOfEra = intdiv($dayOfEra - intdiv($dayOfEra, 1460) + intdiv($dayOfEra, 36524) - intdiv($dayOfEra, 146096), 365);
        $dayOfYear = $dayOfEra - (365 * $yearOfEra + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100));
        $mp = intdiv(5 * $dayOfYear + 2, 153);
        $day = $dayOfYear - intdiv(153 * $mp + 2, 5) + 1;
        $month = $mp < 10 ? $mp + 3 : $mp - 9;

        return [$yearOfEra + $era * 400 + ($month <= 2 ? 1 : 0), $month, $day];
    }

    private static function dateDiff(DateValue $from, DateValue $to, mixed $unit): ?int
    {
        $days = $to->dayNumber() - $from->dayNumber();

        return match ($unit) {
            'days' => $days,
            'weeks' => intdiv($days, 7),
            'months' => self::monthDiff($from, $to),
            'years' => intdiv(self::monthDiff($from, $to), 12),
            default => null,
        };
    }

    private static function monthDiff(DateValue $a, DateValue $b): int
    {
        if ($a->dayNumber() > $b->dayNumber()) {
            return -self::monthDiff($b, $a);
        }

        $months = ($b->year - $a->year) * 12 + ($b->month - $a->month);

        return $b->day < $a->day ? $months - 1 : $months;
    }

    private static function amount(mixed $amount): ?int
    {
        $value = match (true) {
            is_int($amount) || is_float($amount) => JsNumber::safeInteger($amount),
            is_string($amount) && preg_match('/^-?(0|[1-9]\d{0,15})$/D', $amount) === 1 => (int) $amount,
            default => null,
        };

        return $value !== null && abs($value) <= self::MAX_AMOUNT ? $value : null;
    }

    private static function addToDate(DateValue $date, int $amount, string $unit): ?string
    {
        if (! in_array($unit, self::DATE_UNITS, true)) {
            return null;
        }

        if ($unit === 'months' || $unit === 'years') {
            $months = $unit === 'years' ? $amount * 12 : $amount;
            $total = $date->year * 12 + ($date->month - 1) + $months;
            $year = intdiv($total - (($total % 12 + 12) % 12), 12);
            $month = ($total % 12 + 12) % 12 + 1;

            return self::formatDate($year, $month, min($date->day, self::daysInMonth($year, $month)));
        }

        $days = $date->dayNumber() + ($unit === 'weeks' ? $amount * 7 : $amount);
        [$year, $month, $day] = self::civilFromDays($days);

        return self::formatDate($year, $month, $day);
    }

    private static function addToDateTime(DateValue $value, int $amount, string $unit): ?string
    {
        $perUnit = self::DATE_TIME_UNITS[$unit] ?? null;

        if ($perUnit === null) {
            return null;
        }

        $local = $value->dayNumber() * 86400 + $value->hour * 3600 + $value->minute * 60 + $value->second
            + $amount * $perUnit;
        $seconds = (($local % 86400) + 86400) % 86400;
        [$year, $month, $day] = self::civilFromDays(intdiv($local - $seconds, 86400));
        $date = self::formatDate($year, $month, $day);

        if ($date === null) {
            return null;
        }

        return sprintf(
            '%sT%02d:%02d:%02d%s',
            $date, intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60, $value->offset,
        );
    }

    private static function formatDate(int $year, int $month, int $day): ?string
    {
        if ($year < 0 || $year > 9999) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
