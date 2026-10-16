<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

/**
 * Minimal facts about a Chromium-generated PDF. Chromium writes page objects uncompressed
 * (`/Type /Page`), so counting them is reliable; the page tree's `/Count` is the fallback.
 */
final class PdfInfo
{
    public static function isPdf(string $bytes): bool
    {
        return str_starts_with($bytes, '%PDF-');
    }

    public static function pageCount(string $bytes): int
    {
        $pages = preg_match_all('#/Type\s*/Page(?![A-Za-z])#', $bytes);
        if (is_int($pages) && $pages > 0) {
            return $pages;
        }

        preg_match_all('#/Count\s+(\d+)#', $bytes, $m);

        return array_reduce($m[1], static fn (int $max, string $count): int => max($max, (int) $count), 0);
    }
}
