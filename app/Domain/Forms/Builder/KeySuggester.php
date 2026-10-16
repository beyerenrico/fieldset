<?php

declare(strict_types=1);

namespace App\Domain\Forms\Builder;

use Illuminate\Support\Str;

/**
 * Suggests an element key (spec: `^[a-z][a-z0-9_]{0,63}$`) from a label, e.g. "Prüfdatum Übergabe" →
 * `pruefdatum_uebergabe`. German umlauts are spelled out; anything else is transliterated to ASCII.
 */
final class KeySuggester
{
    private const array UMLAUTS = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss'];

    public static function fromLabel(string $label): string
    {
        $ascii = Str::ascii(strtr($label, self::UMLAUTS));
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($ascii)), '_');
        if ($key === '') {
            return '';
        }
        if (preg_match('/^[a-z]/', $key) !== 1) {
            $key = 'field_'.$key;
        }

        return rtrim(substr($key, 0, 64), '_');
    }
}
