<?php

declare(strict_types=1);

namespace Fieldset\Support;

use Fieldset\Support\Pattern\PatternParser;

/**
 * `pattern` constraints in the restricted ECMAScript syntax of spec §3.4, matched through PCRE.
 *
 * Translated patterns run in UTF mode without Unicode properties; every class is spelled out as
 * code point ranges, so \d, \w, \s, \b and `.` have exactly the ECMAScript `u`-mode meaning.
 */
final class EcmaRegex
{
    /** Values longer than this (in code points) fail the check. */
    public const MAX_SUBJECT_LENGTH = 4096;

    /** @var array<string, string|false> */
    private static array $cache = [];

    public static function isValid(string $pattern): bool
    {
        return self::toPcre($pattern) !== null;
    }

    /**
     * True when the value passes. An invalid pattern is no constraint (true); values over 4,096
     * code points, invalid UTF-8 and engine errors (e.g. backtracking limits) fail closed (false).
     */
    public static function matches(string $pattern, string $subject): bool
    {
        $pcre = self::toPcre($pattern);

        if ($pcre === null) {
            return true;
        }

        if (preg_match('//u', $subject) !== 1 || Text::length($subject) > self::MAX_SUBJECT_LENGTH) {
            return false;
        }

        return preg_match($pcre, $subject) === 1;
    }

    /**
     * PCRE (with delimiters) for a valid pattern, or null when it is outside the subset.
     */
    public static function toPcre(string $pattern): ?string
    {
        if (! array_key_exists($pattern, self::$cache)) {
            self::$cache[$pattern] = self::compile($pattern);
        }

        $pcre = self::$cache[$pattern];

        return $pcre === false ? null : $pcre;
    }

    private static function compile(string $pattern): string|false
    {
        $body = PatternParser::translate($pattern);

        if ($body === null) {
            return false;
        }

        $pcre = '~(*UTF)'.$body.'~';

        return @preg_match($pcre, '') === false ? false : $pcre;
    }
}
