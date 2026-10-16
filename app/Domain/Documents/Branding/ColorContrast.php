<?php

declare(strict_types=1);

namespace App\Domain\Documents\Branding;

/**
 * WCAG 2.2 contrast helpers for brand colors (§11, M3 review #14): relative luminance, contrast ratio,
 * the readable text color on a filled brand color, and a brand color darkened until text in it reaches
 * 4.5:1 on white. Input is a validated `#rrggbb` value (Branding); output is lowercase `#rrggbb`.
 */
final class ColorContrast
{
    /** WCAG AA for normal text. */
    public const float AA = 4.5;

    public const string WHITE = '#ffffff';

    /** Text color on light brand fills (Tailwind gray-900, as in the documents' body text). */
    public const string DARK = '#111827';

    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** White or dark text on a fill of $background, whichever contrasts more. */
    public static function textOn(string $background): string
    {
        return self::ratio($background, self::WHITE) >= self::ratio($background, self::DARK) ? self::WHITE : self::DARK;
    }

    /** $color, darkened in 5 % steps toward black until it reaches $min:1 on $background (unchanged if it already does). */
    public static function readableOn(string $color, string $background = self::WHITE, float $min = self::AA): string
    {
        [$r, $g, $b] = self::rgb($color);
        for ($step = 0; $step <= 20; $step++) {
            $factor = 1 - $step * 0.05;
            $candidate = sprintf('#%02x%02x%02x', (int) round($r * $factor), (int) round($g * $factor), (int) round($b * $factor));
            if (self::ratio($candidate, $background) >= $min) {
                return $candidate;
            }
        }

        return '#000000';
    }

    /** Relative luminance per WCAG 2.2 (sRGB). */
    public static function luminance(string $color): float
    {
        $channels = array_map(static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($color));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $color): array
    {
        $hex = preg_match('/^#?([0-9a-f]{6})$/i', trim($color), $m) === 1 ? $m[1] : '000000';

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
