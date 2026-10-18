<?php

declare(strict_types=1);

namespace App\Filament;

use Illuminate\Support\HtmlString;

/**
 * WCAG 1.4.3 AA for Filament's text placeholders of empty table cells and infolist entries ("Never used",
 * "No error"). Filament draws them in gray-400, 2.6:1 on white; gray-500 reaches 4.8:1 on white (4.6:1 on
 * a hovered row) and gray-400 6.7:1 on the dark panel. Same selectors as Filament's, rendered after its
 * stylesheet (STYLES_AFTER), so these win. The panels have no Filament theme to put this in (LEARNINGS #42);
 * the CSP allows inline styles (ADR 0040).
 */
final class AccessiblePlaceholders
{
    private const SELECTORS = ['.fi-ta-cell .fi-ta-placeholder', '.fi-in-entry .fi-in-placeholder'];

    public static function styles(): HtmlString
    {
        $light = implode(',', self::SELECTORS);
        $dark = implode(',', array_map(static fn (string $selector): string => $selector.':where(.dark,.dark *)', self::SELECTORS));

        return new HtmlString("<style>{$light}{color:var(--gray-500)}{$dark}{color:var(--gray-400)}</style>");
    }
}
