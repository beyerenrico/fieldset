<?php

declare(strict_types=1);

namespace FormSchema\Evaluation;

use FormSchema\Definition\Element;
use FormSchema\Json\JsNumber;

/**
 * Validates one value of a visible data element (§5.6): required, then type, then constraints.
 * Only the first failing check is reported.
 */
final class ElementValidator
{
    public static function validate(Element $element, mixed $value, bool $required): ?Violation
    {
        if ($element->isRepeater()) {
            return self::repeater($element, $value, $required);
        }

        if (self::isEmpty($value)) {
            return $required ? new Violation('required') : null;
        }

        $typeViolation = TypeChecker::check($element, $value);

        if ($typeViolation !== null) {
            return $typeViolation;
        }

        if ($element->type === 'checkbox' && $required && $value !== true) {
            return new Violation('required');
        }

        return ConstraintChecker::check($element, $value);
    }

    /**
     * Empty: missing, null, "" or [].
     */
    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * Row-count checks; they also apply to a repeater without rows.
     */
    private static function repeater(Element $element, mixed $value, bool $required): ?Violation
    {
        $count = is_array($value) ? count($value) : 0;

        if ($required && $count === 0) {
            return new Violation('required');
        }

        $min = JsNumber::safeInteger($element->get('minItems'));
        $max = JsNumber::safeInteger($element->get('maxItems'));

        if ($min !== null && $count < $min) {
            return new Violation('min_items', ['limit' => $min]);
        }

        if ($max !== null && $count > $max) {
            return new Violation('max_items', ['limit' => $max]);
        }

        return null;
    }
}
