<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

/**
 * Header/footer tokens (§8.5): `{{org.name}}`, `{{submission.number}}`, `{{submission.date}}`,
 * `{{page}}`, `{{pages}}`. Values are HTML-escaped and inserted in one pass, so a value can never
 * introduce markup or another token; nothing is ever evaluated. `{{page}}`/`{{pages}}` become the
 * Chromium header/footer placeholders. Unknown tokens stay as literal text.
 */
final class TokenReplacer
{
    public const array TOKENS = ['org.name', 'submission.number', 'submission.date', 'page', 'pages'];

    private const array PAGE_PLACEHOLDERS = [
        'page' => '<span class="pageNumber"></span>',
        'pages' => '<span class="totalPages"></span>',
    ];

    /** @param  array<string, string>  $values  token => raw (unescaped) value */
    public static function replace(string $html, array $values): string
    {
        return self::substitute(self::fromMergeTags($html), $values);
    }

    /**
     * The rich editor stores inserted merge tags as `<span data-type="mergeTag" data-id="org.name">`.
     * Turn them back into plain tokens before sanitizing, so typed and inserted tokens behave alike.
     */
    public static function fromMergeTags(string $html): string
    {
        return (string) preg_replace_callback('#<span\b([^>]*)>(.*?)</span>#s', static function (array $m): string {
            if (preg_match('/data-type="mergeTag"/', $m[1]) !== 1 || preg_match('/data-id="([a-z]+(?:\.[a-z]+)?)"/', $m[1], $id) !== 1) {
                return $m[0];
            }

            return '{{'.$id[1].'}}';
        }, $html);
    }

    /** @param  array<string, string>  $values */
    private static function substitute(string $html, array $values): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z]+(?:\.[a-z]+)?)\s*\}\}/', static function (array $m) use ($values): string {
            $token = $m[1];
            if (isset(self::PAGE_PLACEHOLDERS[$token])) {
                return self::PAGE_PLACEHOLDERS[$token];
            }
            if (! in_array($token, self::TOKENS, true)) {
                return $m[0];
            }

            return htmlspecialchars($values[$token] ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }, $html);
    }
}
