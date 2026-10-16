<?php

declare(strict_types=1);

namespace App\Support\Dates;

use Carbon\CarbonInterface;
use IntlDateFormatter;
use IntlDatePatternGenerator;

/**
 * Locale-aware date labels (BUILD_PROMPT §11 "dates formatted according to the locale"): a numeric date
 * (de 17.10.2026, en 10/17/2026) and a short day + month without year (de 17.10., en 10/17), as the
 * locale's own pattern (ICU), never a hard-coded German format.
 */
final class LocalDate
{
    public static function date(CarbonInterface $date, ?string $locale = null): string
    {
        return $date->settings(['locale' => $locale ?? app()->getLocale()])->isoFormat('L');
    }

    public static function dayMonth(CarbonInterface $date, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $pattern = new IntlDatePatternGenerator($locale)->getBestPattern('ddMM');
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $date->getTimezone()->getName(), null, $pattern ?: 'dd.MM.');
        $text = $formatter->format($date->toDateTimeImmutable());

        return is_string($text) ? $text : $date->format('d.m.');
    }

    /** "17.10. – 23.10.2026" / "10/17 – 10/23/2026"; one date when both ends are the same day. */
    public static function range(CarbonInterface $start, CarbonInterface $end, ?string $locale = null): string
    {
        return $start->isSameDay($end) ? self::date($start, $locale) : self::dayMonth($start, $locale).' – '.self::date($end, $locale);
    }
}
