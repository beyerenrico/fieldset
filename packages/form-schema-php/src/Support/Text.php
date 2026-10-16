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

    /**
     * Code point of a single UTF-8 encoded character.
     */
    public static function ord(string $char): int
    {
        $first = ord($char[0] ?? "\0");

        return match (true) {
            $first < 0x80 => $first,
            $first < 0xE0 => (($first & 0x1F) << 6) | self::continuation($char, 1),
            $first < 0xF0 => (($first & 0x0F) << 12) | (self::continuation($char, 1) << 6) | self::continuation($char, 2),
            default => (($first & 0x07) << 18) | (self::continuation($char, 1) << 12)
                | (self::continuation($char, 2) << 6) | self::continuation($char, 3),
        };
    }

    private static function continuation(string $char, int $index): int
    {
        return ord($char[$index] ?? "\0") & 0x3F;
    }
}
