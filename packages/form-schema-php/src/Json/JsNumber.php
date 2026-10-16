<?php

declare(strict_types=1);

namespace FormSchema\Json;

/**
 * ECMAScript number semantics: Number::toString and Number(string).
 */
final class JsNumber
{
    /** 2^53, the largest integer a double holds exactly. */
    public const MAX_SAFE_INTEGER = 9007199254740992;

    private const WHITESPACE = '[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}'
        .'\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    private const DECIMAL_LITERAL = '(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?';

    /**
     * Shortest round-trip string like JavaScript String(n): integral values without
     * fraction, exponent form below 1e-6 and from 1e21.
     */
    public static function toString(int|float $number): string
    {
        if (is_int($number) && abs($number) <= self::MAX_SAFE_INTEGER) {
            return (string) $number;
        }

        $number = (float) $number;

        if (is_nan($number)) {
            return 'NaN';
        }

        if (is_infinite($number)) {
            return $number > 0 ? 'Infinity' : '-Infinity';
        }

        if ($number === 0.0) {
            return '0';
        }

        [$digits, $pointPosition] = self::shortestDigits(abs($number));

        return ($number < 0 ? '-' : '').self::format($digits, $pointPosition);
    }

    /**
     * True for a JSON number whose double value is integral and within ±2^53.
     */
    public static function isSafeInteger(mixed $value): bool
    {
        return self::safeInteger($value) !== null;
    }

    /**
     * The integer a JavaScript double holds for this JSON number (integers beyond 2^53 are
     * rounded like JSON.parse does), or null when it is not an integer within ±2^53.
     */
    public static function safeInteger(mixed $value): ?int
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $double = (float) $value;

        if (! is_finite($double) || floor($double) !== $double || abs($double) > self::MAX_SAFE_INTEGER) {
            return null;
        }

        return (int) $double;
    }

    /**
     * JavaScript Number(string).
     */
    public static function fromString(string $value): float
    {
        $trimmed = self::trim($value);

        if ($trimmed === '') {
            return 0.0;
        }

        if (preg_match('/^([+-]?)Infinity$/D', $trimmed, $match) === 1) {
            return $match[1] === '-' ? -INF : INF;
        }

        if (preg_match('/^[+-]?'.self::DECIMAL_LITERAL.'$/D', $trimmed) === 1) {
            return (float) $trimmed;
        }

        return self::fromPrefixedInteger($trimmed);
    }

    private static function fromPrefixedInteger(string $value): float
    {
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/D', $value, $match) === 1) {
            return (float) hexdec($match[1]);
        }

        if (preg_match('/^0[oO]([0-7]+)$/D', $value, $match) === 1) {
            return (float) octdec($match[1]);
        }

        if (preg_match('/^0[bB]([01]+)$/D', $value, $match) === 1) {
            return (float) bindec($match[1]);
        }

        return NAN;
    }

    private static function trim(string $value): string
    {
        return preg_replace('/^'.self::WHITESPACE.'+|'.self::WHITESPACE.'+$/u', '', $value) ?? $value;
    }

    /**
     * Shortest round-trip digits of a positive finite float.
     *
     * @return array{string, int} digits without leading/trailing zeros, and n such that
     *                            value = 0.digits × 10^n
     */
    private static function shortestDigits(float $value): array
    {
        $previous = ini_set('serialize_precision', '-1');

        try {
            $repr = var_export($value, true);
        } finally {
            if ($previous !== false) {
                ini_set('serialize_precision', $previous);
            }
        }

        preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/D', $repr, $match);
        $integer = $match[1] ?? '0';
        $fraction = $match[2] ?? '';
        $exponent = (int) ($match[3] ?? 0) - strlen($fraction);

        $digits = ltrim($integer.$fraction, '0');
        $trimmed = rtrim($digits, '0');
        $exponent += strlen($digits) - strlen($trimmed);

        return [$trimmed, strlen($trimmed) + $exponent];
    }

    private static function format(string $digits, int $n): string
    {
        $k = strlen($digits);

        if ($k <= $n && $n <= 21) {
            return $digits.str_repeat('0', $n - $k);
        }

        if ($n > 0 && $n <= 21) {
            return substr($digits, 0, $n).'.'.substr($digits, $n);
        }

        if ($n > -6 && $n <= 0) {
            return '0.'.str_repeat('0', -$n).$digits;
        }

        $exponent = $n - 1;
        $sign = $exponent < 0 ? '-' : '+';
        $mantissa = $k === 1 ? $digits : $digits[0].'.'.substr($digits, 1);

        return $mantissa.'e'.$sign.abs($exponent);
    }
}
