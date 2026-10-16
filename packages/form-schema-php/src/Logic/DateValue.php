<?php

declare(strict_types=1);

namespace FormSchema\Logic;

use Brick\Math\BigDecimal;

/**
 * A parsed `YYYY-MM-DD` date or RFC 3339 date-time with offset (spec §3.2, §5.6).
 */
final readonly class DateValue
{
    private const DATE = '/^(\d{4})-(\d{2})-(\d{2})$/D';

    private const DATE_TIME = '/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(\.\d+)?'
        .'(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D';

    private const TIME = '/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/D';

    public function __construct(
        public bool $hasTime,
        public int $year,
        public int $month,
        public int $day,
        public int $hour = 0,
        public int $minute = 0,
        public int $second = 0,
        public string $fraction = '',
        public string $offset = '',
    ) {}

    public static function parseDate(mixed $value): ?self
    {
        if (! is_string($value) || preg_match(self::DATE, $value, $m) !== 1) {
            return null;
        }

        $date = new self(false, (int) $m[1], (int) $m[2], (int) $m[3]);

        return $date->isRealDate() ? $date : null;
    }

    public static function parseDateTime(mixed $value): ?self
    {
        if (! is_string($value) || preg_match(self::DATE_TIME, $value, $m) !== 1) {
            return null;
        }

        $dateTime = new self(
            true, (int) $m[1], (int) $m[2], (int) $m[3],
            (int) $m[4], (int) $m[5], (int) $m[6], ltrim($m[7], '.'), $m[8],
        );

        return $dateTime->isRealDate() ? $dateTime : null;
    }

    /**
     * Seconds since midnight of a `HH:MM` or `HH:MM:SS` value, or null when malformed.
     */
    public static function parseTime(mixed $value): ?int
    {
        if (! is_string($value) || preg_match(self::TIME, $value, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) ($m[3] ?? 0);
    }

    /**
     * Days since 1970-01-01 of the calendar date (local date for date-times).
     */
    public function dayNumber(): int
    {
        return DateMath::daysFromCivil($this->year, $this->month, $this->day);
    }

    /**
     * Whole seconds since the Unix epoch of the instant (fraction dropped); midnight UTC for dates.
     */
    public function epochSeconds(): int
    {
        $local = $this->dayNumber() * 86400 + $this->hour * 3600 + $this->minute * 60 + $this->second;

        return $local - $this->offsetSeconds();
    }

    /**
     * Exact instant in seconds since the Unix epoch, fractional seconds included.
     */
    public function instant(): BigDecimal
    {
        $seconds = BigDecimal::of($this->epochSeconds());

        return $this->fraction === '' ? $seconds : $seconds->plus('0.'.$this->fraction);
    }

    public function offsetSeconds(): int
    {
        if ($this->offset === '' || $this->offset === 'Z') {
            return 0;
        }

        $sign = $this->offset[0] === '-' ? -1 : 1;

        return $sign * ((int) substr($this->offset, 1, 2) * 3600 + (int) substr($this->offset, 4, 2) * 60);
    }

    private function isRealDate(): bool
    {
        return $this->month >= 1 && $this->month <= 12
            && $this->day >= 1 && $this->day <= DateMath::daysInMonth($this->year, $this->month);
    }
}
