<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

use DateTimeInterface;

/**
 * Renders document numbers such as `SR-{YYYY}-{SEQ:5}` (spec §7.3) and derives the sequence period
 * from the reset rule. Without a numbering setting forms use `{SEQ:6}`, never reset.
 */
final class NumberFormat
{
    public const string DEFAULT_FORMAT = '{SEQ:6}';

    public static function period(string $reset, DateTimeInterface $at): string
    {
        return match ($reset) {
            'yearly' => $at->format('Y'),
            'monthly' => $at->format('Y-m'),
            default => 'all',
        };
    }

    public static function render(string $format, DateTimeInterface $at, int $sequence): string
    {
        return (string) preg_replace_callback(
            '/\{(YYYY|YY|MM|SEQ:(\d{1,2}))\}/',
            static fn (array $m): string => match (true) {
                $m[1] === 'YYYY' => $at->format('Y'),
                $m[1] === 'YY' => $at->format('y'),
                $m[1] === 'MM' => $at->format('m'),
                default => str_pad((string) $sequence, (int) ($m[2] ?? 1), '0', STR_PAD_LEFT),
            },
            $format,
        );
    }
}
