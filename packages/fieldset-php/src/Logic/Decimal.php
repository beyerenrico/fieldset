<?php

declare(strict_types=1);

namespace Fieldset\Logic;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Fieldset\Json\JsNumber;

/**
 * Decimal rules of the spec (§5.1, §5.3): canonical decimal strings, operand parsing,
 * scale rules and half-up (away from zero) rounding.
 */
final class Decimal
{
    public const PATTERN = '/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/D';

    public const MAX_DIVISION_SCALE = 20;

    /** Decimal strings hold at most 60 digits (integer plus fraction digits, spec §5.1). */
    public const MAX_DIGITS = 60;

    public static function isDecimalString(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1
            && self::digits($value) <= self::MAX_DIGITS;
    }

    /**
     * Number of digits of a decimal string (sign and point excluded).
     */
    public static function digits(string $value): int
    {
        return strlen($value) - substr_count($value, '-') - substr_count($value, '.');
    }

    /**
     * Operand rule (§5.3): decimal strings and JSON integers (integral values up to 2^53).
     * Everything else counts as null.
     */
    public static function parse(mixed $value): ?BigDecimal
    {
        if (is_string($value)) {
            return self::isDecimalString($value) ? BigDecimal::of($value) : null;
        }

        $integer = JsNumber::safeInteger($value);

        return $integer === null ? null : BigDecimal::of($integer);
    }

    /**
     * Constraint values in a definition: JSON numbers (shortest round-trip form) or decimal strings.
     */
    public static function fromConstraint(mixed $value): ?BigDecimal
    {
        if (is_float($value)) {
            return is_finite($value) ? BigDecimal::fromFloatShortest($value) : null;
        }

        if (is_int($value)) {
            return BigDecimal::of($value);
        }

        return is_string($value) && self::isDecimalString($value) ? BigDecimal::of($value) : null;
    }

    /**
     * Canonical output form: no exponent, no `+`, no negative zero, scale kept.
     */
    public static function format(BigDecimal $value): string
    {
        return $value->toString();
    }

    /**
     * Rounds half-up (away from zero) to exactly $scale fraction digits.
     *
     * @param  int<0, max>  $scale
     */
    public static function toScale(BigDecimal $value, int $scale): BigDecimal
    {
        return $value->toScale($scale, RoundingMode::HalfUp);
    }

    /**
     * Scale argument of dec.div / dec.round: a JSON integer from 0 to $max, otherwise null.
     *
     * @return int<0, max>|null
     */
    public static function scaleOperand(mixed $value, int $max = self::MAX_DIVISION_SCALE): ?int
    {
        $scale = JsNumber::safeInteger($value);

        return $scale === null || $scale < 0 || $scale > $max ? null : $scale;
    }

    /**
     * Number of fraction digits of a decimal string.
     */
    public static function fractionDigits(string $value): int
    {
        $point = strpos($value, '.');

        return $point === false ? 0 : strlen($value) - $point - 1;
    }

    /**
     * Parses all operands; null when any is null or there are none.
     *
     * @param  list<mixed>  $operands
     * @return non-empty-list<BigDecimal>|null
     */
    public static function parseAll(array $operands): ?array
    {
        if ($operands === []) {
            return null;
        }

        $parsed = [];
        foreach ($operands as $operand) {
            $decimal = self::parse($operand);

            if ($decimal === null) {
                return null;
            }

            $parsed[] = $decimal;
        }

        return $parsed;
    }

    /**
     * Numeric comparison of two operands; null when either is null.
     */
    public static function compare(mixed $a, mixed $b): ?int
    {
        $left = self::parse($a);
        $right = self::parse($b);

        if ($left === null || $right === null) {
            return null;
        }

        return $left->compareTo($right);
    }
}
