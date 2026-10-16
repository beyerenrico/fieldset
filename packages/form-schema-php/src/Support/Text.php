<?php

declare(strict_types=1);

namespace FormSchema\Support;

/**
 * Unicode code point helpers that work without ext-mbstring.
 */
final class Text
{
    /**
     * @return list<string> the code points of a UTF-8 string (bytes for invalid UTF-8)
     */
    public static function codePoints(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $points = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        return $points === false ? str_split($value) : $points;
    }

    public static function length(string $value): int
    {
        return count(self::codePoints($value));
    }
}
