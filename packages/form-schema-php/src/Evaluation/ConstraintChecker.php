<?php

declare(strict_types=1);

namespace FormSchema\Evaluation;

use Brick\Math\BigDecimal;
use FormSchema\Definition\Element;
use FormSchema\Json\JsNumber;
use FormSchema\Logic\DateValue;
use FormSchema\Logic\Decimal;
use FormSchema\Support\EcmaRegex;
use FormSchema\Support\Text;
use stdClass;

/**
 * Constraint checks of §3.3 for a non-empty, type-valid value.
 */
final class ConstraintChecker
{
    public static function check(Element $element, mixed $value): ?Violation
    {
        $rules = $element->validation();

        return match ($element->type) {
            'text', 'textarea', 'phone' => self::length($rules, $value) ?? self::pattern($rules, $value),
            'email' => self::length($rules, $value),
            'number', 'money' => self::decimal($rules, $value),
            'date', 'time', 'datetime' => self::ordered($element->type, $rules, $value),
            'multiselect' => self::count($rules, $value),
            'photo', 'file' => self::files($element, $rules, $value),
            'rating' => self::rating($element, $value),
            default => null,
        };
    }

    private static function length(stdClass $rules, mixed $value): ?Violation
    {
        if (! is_string($value)) {
            return null;
        }

        $length = Text::length($value);
        $min = JsNumber::safeInteger($rules->minLength ?? null);
        $max = JsNumber::safeInteger($rules->maxLength ?? null);

        if ($min !== null && $length < $min) {
            return new Violation('min_length', ['limit' => $min]);
        }

        if ($max !== null && $length > $max) {
            return new Violation('max_length', ['limit' => $max]);
        }

        return null;
    }

    private static function pattern(stdClass $rules, mixed $value): ?Violation
    {
        $pattern = $rules->pattern ?? null;

        if (! is_string($pattern) || ! is_string($value) || EcmaRegex::matches($pattern, $value)) {
            return null;
        }

        return new Violation('pattern');
    }

    private static function decimal(stdClass $rules, mixed $value): ?Violation
    {
        $number = Decimal::parse($value);

        if ($number === null) {
            return null;
        }

        $min = Decimal::fromConstraint($rules->min ?? null);
        $max = Decimal::fromConstraint($rules->max ?? null);

        if ($min !== null && $number->isLessThan($min)) {
            return new Violation('min', ['limit' => $rules->min ?? null]);
        }

        if ($max !== null && $number->isGreaterThan($max)) {
            return new Violation('max', ['limit' => $rules->max ?? null]);
        }

        return self::step($rules, $number, $min);
    }

    private static function step(stdClass $rules, BigDecimal $number, ?BigDecimal $min): ?Violation
    {
        $step = Decimal::fromConstraint($rules->step ?? null);

        if ($step === null || ! $step->isPositive()) {
            return null;
        }

        $offset = $number->minus($min ?? BigDecimal::zero());

        return $offset->remainder($step)->isZero() ? null : new Violation('step', ['step' => $rules->step ?? null]);
    }

    /**
     * Inclusive bounds given as value strings of the same type.
     */
    private static function ordered(string $type, stdClass $rules, mixed $value): ?Violation
    {
        $current = self::position($type, $value);
        $min = self::position($type, $rules->min ?? null);
        $max = self::position($type, $rules->max ?? null);

        if ($current === null) {
            return null;
        }

        if ($min !== null && $current->isLessThan($min)) {
            return new Violation('min', ['limit' => $rules->min ?? null]);
        }

        if ($max !== null && $current->isGreaterThan($max)) {
            return new Violation('max', ['limit' => $rules->max ?? null]);
        }

        return null;
    }

    /**
     * Orderable position of a date (day number), time (second of day) or date-time (instant).
     */
    private static function position(string $type, mixed $value): ?BigDecimal
    {
        $position = match ($type) {
            'date' => DateValue::parseDate($value)?->dayNumber(),
            'time' => DateValue::parseTime($value),
            default => DateValue::parseDateTime($value)?->instant(),
        };

        return $position === null ? null : BigDecimal::of($position);
    }

    private static function count(stdClass $rules, mixed $value): ?Violation
    {
        $count = is_array($value) ? count($value) : 0;
        $min = $rules->min ?? null;
        $max = $rules->max ?? null;

        if ((is_int($min) || is_float($min)) && $count < $min) {
            return new Violation('min', ['limit' => $min]);
        }

        if ((is_int($max) || is_float($max)) && $count > $max) {
            return new Violation('max', ['limit' => $max]);
        }

        return null;
    }

    private static function files(Element $element, stdClass $rules, mixed $value): ?Violation
    {
        $limit = JsNumber::safeInteger($rules->maxFiles ?? null);

        if ($element->get('multiple') !== true || $limit === null || ! is_array($value) || count($value) <= $limit) {
            return null;
        }

        return new Violation('max_items', ['limit' => $limit]);
    }

    private static function rating(Element $element, mixed $value): ?Violation
    {
        $max = $element->int('max', 5);

        $rating = JsNumber::safeInteger($value);

        if ($rating !== null && $rating >= 1 && $rating <= $max) {
            return null;
        }

        return new Violation('out_of_range');
    }
}
