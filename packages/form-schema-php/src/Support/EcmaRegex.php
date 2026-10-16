<?php

declare(strict_types=1);

namespace FormSchema\Support;

/**
 * ECMAScript-compatible `pattern` constraints (spec §3.3) translated to PCRE.
 *
 * Patterns are implicitly unanchored; lookbehind and backreferences are rejected.
 */
final class EcmaRegex
{
    /** Unicode white space that JavaScript's `\s` includes beyond ASCII. */
    private const UNICODE_SPACES = '\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    public static function isValid(string $pattern): bool
    {
        return self::toPcre($pattern) !== null;
    }

    /**
     * True when the subject matches; an invalid pattern never rejects a value.
     */
    public static function matches(string $pattern, string $subject): bool
    {
        $pcre = self::toPcre($pattern);

        return $pcre === null || preg_match($pcre, $subject) === 1;
    }

    /**
     * PCRE equivalent (with delimiters and modifiers), or null when invalid or unsupported.
     */
    public static function toPcre(string $pattern): ?string
    {
        $body = self::translate($pattern);

        if ($body === null) {
            return null;
        }

        $pcre = '/'.$body.'/uD';

        return @preg_match($pcre, '') === false ? null : $pcre;
    }

    private static function translate(string $pattern): ?string
    {
        $out = '';
        $inClass = false;
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\') {
                $escape = self::escape($pattern, $i, $inClass);

                if ($escape === null) {
                    return null;
                }

                [$text, $consumed] = $escape;
                $out .= $text;
                $i += $consumed;

                continue;
            }

            if (! $inClass && $char === '(' && preg_match('/\G\(\?<[=!]/', $pattern, $m, 0, $i) === 1) {
                return null;
            }

            [$text, $inClass, $skip] = self::plain($pattern, $i, $inClass);
            $out .= $text;
            $i += $skip;
        }

        return $out;
    }

    /**
     * @return array{string, bool, int} translated text, new class state, extra bytes consumed
     */
    private static function plain(string $pattern, int $i, bool $inClass): array
    {
        $char = $pattern[$i];

        if (! $inClass && $char === '[') {
            if (substr($pattern, $i, 3) === '[^]') {
                return ['[\s\S]', false, 2];
            }

            if (substr($pattern, $i, 2) === '[]') {
                return ['(?!)', false, 1];
            }

            return ['[', true, 0];
        }

        if ($inClass && $char === ']') {
            return [']', false, 0];
        }

        return match (true) {
            $char === '/' => ['\/', $inClass, 0],
            $char === '.' && ! $inClass => ['[^\n\r\x{2028}\x{2029}]', false, 0],
            default => [$char, $inClass, 0],
        };
    }

    /**
     * @return array{string, int}|null translated escape and extra bytes consumed; null if unsupported
     */
    private static function escape(string $pattern, int $i, bool $inClass): ?array
    {
        if (preg_match('/\Gu([0-9a-fA-F]{4})/', $pattern, $m, 0, $i + 1) === 1) {
            return ['\x{'.$m[1].'}', 5];
        }

        if (preg_match('/\G./su', $pattern, $m, 0, $i + 1) !== 1) {
            return null;
        }

        $next = $m[0];

        if (! $inClass && (preg_match('/^[1-9]$/', $next) === 1 || substr($pattern, $i + 1, 2) === 'k<')) {
            return null;
        }

        return [self::escapeText($next, $inClass), strlen($next)];
    }

    /**
     * JavaScript escapes that differ from PCRE; letters without meaning are identity escapes.
     */
    private static function escapeText(string $char, bool $inClass): string
    {
        return match (true) {
            $char === 's' => $inClass ? '\s'.self::UNICODE_SPACES : '[\s'.self::UNICODE_SPACES.']',
            $char === 'S' && ! $inClass => '[^\s'.self::UNICODE_SPACES.']',
            $char === 'v' => '\x0B',
            $char === '0' => '\x00',
            strlen($char) > 1 => $char,
            preg_match('/^[a-zA-Z]$/', $char) === 1 && preg_match('/^[dDwWsSbBtnfrcxk]$/', $char) !== 1 => $char,
            default => '\\'.$char,
        };
    }
}
