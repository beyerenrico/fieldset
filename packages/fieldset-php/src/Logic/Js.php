<?php

declare(strict_types=1);

namespace Fieldset\Logic;

use Fieldset\Json\JsNumber;
use stdClass;

/**
 * JavaScript value semantics restricted to JSON values (spec §4.1).
 */
final class Js
{
    /**
     * Falsy: false, null, 0, NaN, "", []. Everything else (including "0" and {}) is truthy.
     */
    public static function truthy(mixed $value): bool
    {
        return match (true) {
            $value === null, $value === false, $value === '', $value === [] => false,
            is_int($value) => $value !== 0,
            is_float($value) => $value !== 0.0 && ! is_nan($value),
            default => true,
        };
    }

    /**
     * JavaScript abstract equality (==) restricted to JSON values: objects are never equal, arrays
     * compare through their comma-joined string form (also with each other).
     */
    public static function looseEquals(mixed $a, mixed $b): bool
    {
        if ($a instanceof stdClass || $b instanceof stdClass) {
            return false;
        }

        $left = is_array($a) ? self::toJsString($a) : $a;
        $right = is_array($b) ? self::toJsString($b) : $b;

        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        if (is_bool($left) || is_bool($right)) {
            return self::looseEquals(self::boolToInt($left), self::boolToInt($right));
        }

        if (is_string($left) && is_string($right)) {
            return $left === $right;
        }

        return self::toNumber($left) === self::toNumber($right);
    }

    /**
     * JavaScript strict equality (===) for JSON values. Objects and arrays are never strictly equal.
     */
    public static function strictEquals(mixed $a, mixed $b): bool
    {
        if (self::isNumber($a) && self::isNumber($b)) {
            return (float) $a === (float) $b;
        }

        if (is_array($a) || is_array($b) || is_object($a) || is_object($b)) {
            return false;
        }

        return $a === $b;
    }

    /**
     * Relational comparison (<, >): strings by code point, everything else as numbers.
     *
     * @return int|null -1, 0, 1, or null when a NaN is involved
     */
    public static function compare(mixed $a, mixed $b): ?int
    {
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }

        $x = self::toNumber($a);
        $y = self::toNumber($b);

        if (is_nan($x) || is_nan($y)) {
            return null;
        }

        return $x <=> $y;
    }

    /**
     * Number conversion for relational operators and arithmetic: null → 0, booleans → 1/0,
     * strings via JavaScript Number(string), arrays and objects → NaN.
     */
    public static function toNumber(mixed $value): float
    {
        return match (true) {
            $value === null => 0.0,
            is_bool($value) => $value ? 1.0 : 0.0,
            is_int($value), is_float($value) => (float) $value,
            is_string($value) => JsNumber::fromString($value),
            default => NAN,
        };
    }

    /**
     * JavaScript String(value) for JSON values.
     */
    public static function toJsString(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => JsNumber::toString($value),
            is_string($value) => $value,
            is_array($value) => implode(',', array_map(
                static fn (mixed $item): string => $item === null ? '' : self::toJsString($item),
                $value,
            )),
            default => '[object Object]',
        };
    }

    /**
     * String form used by `cat`: null becomes "", everything else String(value).
     */
    public static function catString(mixed $value): string
    {
        return $value === null ? '' : self::toJsString($value);
    }

    /**
     * @phpstan-assert-if-true int|float $value
     */
    public static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    private static function boolToInt(mixed $value): mixed
    {
        return is_bool($value) ? (int) $value : $value;
    }
}
