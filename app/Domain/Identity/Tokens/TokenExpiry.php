<?php

declare(strict_types=1);

namespace App\Domain\Identity\Tokens;

use Carbon\CarbonImmutable;

/**
 * Expiry choices of a new integration token (ADR 0043): a number of days or never. The preselected choice is
 * `api_tokens.default_expiry_days` (90 unless configured).
 */
final class TokenExpiry
{
    public const string NEVER = 'never';

    /** @var list<int> */
    private const array DAYS = [7, 30, 90, 180, 365];

    public static function defaultDays(): int
    {
        $days = config('api_tokens.default_expiry_days', 90);

        return is_numeric($days) && (int) $days > 0 ? (int) $days : 90;
    }

    public static function defaultChoice(): string
    {
        return (string) self::defaultDays();
    }

    /** @return array<int|string, string> choice (days, or `never`) => label; PHP turns numeric choices into int keys */
    public static function options(): array
    {
        $days = array_values(array_unique([...self::DAYS, self::defaultDays()]));
        sort($days);

        $options = [];
        foreach ($days as $day) {
            $options[$day] = trans_choice('api_tokens.expiry.days', $day, ['days' => $day]);
        }
        $options[self::NEVER] = (string) __('api_tokens.expiry.never');

        return $options;
    }

    /** The expiry of a choice; null means the token never expires. */
    public static function resolve(string $choice): ?CarbonImmutable
    {
        if ($choice === self::NEVER) {
            return null;
        }

        return CarbonImmutable::now()->addDays(max(1, (int) $choice));
    }
}
