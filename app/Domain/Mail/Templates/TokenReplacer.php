<?php

declare(strict_types=1);

namespace App\Domain\Mail\Templates;

use Closure;

/**
 * Replaces {{ tokens }} in tenant-authored subjects and Markdown bodies (§8.8). Values are data,
 * never code: the template is not compiled by Blade, replacement runs in a single pass (a value
 * that looks like a token stays literal) and unknown tokens are left as written.
 */
final class TokenReplacer
{
    private const string PATTERN = '/\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*)\s*\}\}/i';

    /** Characters that HTML would interpret; replaced by entities (CommonMark decodes and re-escapes them). */
    private const array ENTITIES = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;'];

    /** Remaining ASCII punctuation; CommonMark treats a backslash-escaped one as a literal character. */
    private const string MARKDOWN_PUNCTUATION = '!#$%()*+,-./:;=?@[\\]^_`{|}~';

    /**
     * For Markdown bodies: every value renders as literal text, never as markup, link or HTML.
     *
     * @param  array<string, string>  $tokens
     */
    public function markdown(string $template, array $tokens): string
    {
        return $this->replace($template, $tokens, self::escapeMarkdown(...));
    }

    /**
     * For subjects and plain-text parts: values are reduced to a single line without control characters.
     *
     * @param  array<string, string>  $tokens
     */
    public function plain(string $template, array $tokens): string
    {
        return $this->replace($template, $tokens, self::singleLine(...));
    }

    public static function escapeMarkdown(string $value): string
    {
        $escaped = '';
        foreach (mb_str_split(self::singleLine($value)) as $char) {
            $escaped .= match (true) {
                isset(self::ENTITIES[$char]) => self::ENTITIES[$char],
                str_contains(self::MARKDOWN_PUNCTUATION, $char) => '\\'.$char,
                default => $char,
            };
        }

        return $escaped;
    }

    public static function singleLine(string $value): string
    {
        $value = (string) preg_replace('/[\r\n\t\x{2028}\x{2029}]+/u', ' ', $value);

        return trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value));
    }

    /**
     * @param  array<string, string>  $tokens
     * @param  Closure(string): string  $escape
     */
    private function replace(string $template, array $tokens, Closure $escape): string
    {
        return (string) preg_replace_callback(self::PATTERN, static function (array $match) use ($tokens, $escape): string {
            $key = strtolower($match[1]);

            return array_key_exists($key, $tokens) ? $escape($tokens[$key]) : $match[0];
        }, $template);
    }
}
