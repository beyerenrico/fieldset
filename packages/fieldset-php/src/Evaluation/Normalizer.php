<?php

declare(strict_types=1);

namespace Fieldset\Evaluation;

use Brick\Math\BigDecimal;
use Fieldset\Definition\Definition;
use Fieldset\Definition\Element;
use Fieldset\Definition\Scope;
use Fieldset\Json\JsonPointer;
use stdClass;

/**
 * Step 1 of §5.5: keeps only keys defined in the scope (recursively in rows), turns non-array
 * repeater values into [] and drops non-object rows (both reported as `type_mismatch`), rewrites
 * money values to exactly `scale` fraction digits and sets every calculated value to null.
 */
final class Normalizer
{
    public static function normalize(Definition $definition, mixed $input): NormalizedData
    {
        $root = $input instanceof stdClass ? $input : new stdClass;
        [$values, $errors] = self::scope($definition, $definition->rootScope(), $root, '');

        return new NormalizedData($values, $errors);
    }

    /**
     * Normalized value for a default result: money rewritten, repeater rows cleaned silently.
     * Null when the result cannot be used (null, or a non-array repeater default).
     */
    public static function defaultValue(Definition $definition, Element $element, mixed $value): mixed
    {
        if ($element->isRepeater()) {
            return is_array($value) && array_is_list($value) ? self::rows($definition, $element, $value, '')[0] : null;
        }

        return self::value($element, $value);
    }

    /**
     * @return array{array<string, mixed>, list<ValidationError>}
     */
    private static function scope(Definition $definition, Scope $scope, stdClass $input, string $prefix): array
    {
        $values = [];
        $errors = [];

        foreach (get_object_vars($input) as $key => $value) {
            $key = (string) $key;
            $element = $scope->element($key);

            if ($element === null || $element->isCalculated()) {
                continue;
            }

            if (! $element->isRepeater()) {
                $values[$key] = self::value($element, $value);

                continue;
            }

            [$rows, $rowErrors] = self::rows($definition, $element, $value, JsonPointer::append($prefix, $key));
            $values[$key] = $rows;
            array_push($errors, ...$rowErrors);
        }

        foreach ($scope->elements as $key => $element) {
            if ($element->isCalculated()) {
                $values[$key] = null;
            }
        }

        return [$values, $errors];
    }

    /**
     * @return array{list<array<string, mixed>>|null, list<ValidationError>}
     */
    private static function rows(Definition $definition, Element $repeater, mixed $value, string $pointer): array
    {
        if ($value === null) {
            return [null, []];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return [[], [new ValidationError($pointer, 'type_mismatch')]];
        }

        $rowScope = $definition->scope($repeater->rowScopeId());
        $rows = [];
        $errors = [];

        foreach ($value as $index => $row) {
            if (! $row instanceof stdClass || $rowScope === null) {
                $errors[] = new ValidationError(JsonPointer::append($pointer, $index), 'type_mismatch');

                continue;
            }

            [$rowValues, $rowErrors] = self::scope($definition, $rowScope, $row, JsonPointer::append($pointer, count($rows)));
            $rows[] = $rowValues;
            array_push($errors, ...$rowErrors);
        }

        return [$rows, $errors];
    }

    private static function value(Element $element, mixed $value): mixed
    {
        if ($element->type !== 'money' || ! is_string($value) || ! TypeChecker::passes($element, $value)) {
            return $value;
        }

        return BigDecimal::of($value)->toScale($element->scale())->toString();
    }
}
