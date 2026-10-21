<?php

declare(strict_types=1);

namespace Fieldset\Lint;

/**
 * Numbering format of `settings.numbering` (spec §7.3).
 */
final class NumberingFormat
{
    private const GRAMMAR = '/^(?:\{(?:YYYY|YY|MM|SEQ:(?:[1-9]|1[0-2]))\}|[A-Za-z0-9._\/-])+$/D';

    public static function isValid(string $format, string $reset = 'never'): bool
    {
        if (preg_match(self::GRAMMAR, $format) !== 1 || substr_count($format, '{SEQ:') !== 1) {
            return false;
        }

        $hasYear = str_contains($format, '{YYYY}') || str_contains($format, '{YY}');

        return match ($reset) {
            'yearly' => $hasYear,
            'monthly' => $hasYear && str_contains($format, '{MM}'),
            default => true,
        };
    }
}
