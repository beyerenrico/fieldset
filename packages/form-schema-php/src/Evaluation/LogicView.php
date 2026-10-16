<?php

declare(strict_types=1);

namespace FormSchema\Evaluation;

use FormSchema\Definition\Definition;
use FormSchema\Definition\Element;
use FormSchema\Definition\Scope;
use FormSchema\Json\JsonPointer;
use stdClass;

/**
 * The data as logic sees it (§4.3, §5.5): every defined key is present; non-empty values failing
 * their type check and values of invisible elements are null.
 */
final class LogicView
{
    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<string, bool>  $visibility
     */
    public static function build(Definition $definition, array $values, array $visibility): stdClass
    {
        return self::scopeView($definition, $definition->rootScope(), $values, '', $visibility);
    }

    /**
     * The scope object for an element in the given row: built-ins, `$root`, root keys, then the
     * keys of each enclosing row (shadowing outer keys), and `$row` for the innermost row.
     *
     * @param  list<array{string, int}>  $rowPath
     */
    public static function scope(stdClass $rootView, array $rowPath, EvaluationContext $context): stdClass
    {
        $scope = $context->builtins();
        $scope->{'$root'} = $rootView;
        self::copyInto($scope, $rootView);
        $current = $rootView;

        foreach ($rowPath as [$key, $index]) {
            $rows = $current->{$key} ?? null;
            $row = is_array($rows) ? ($rows[$index] ?? null) : null;

            if (! $row instanceof stdClass) {
                break;
            }

            self::copyInto($scope, $row);
            $current = $row;
        }

        if ($rowPath !== []) {
            $scope->{'$row'} = $current;
        }

        return $scope;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, bool>  $visibility
     */
    private static function scopeView(Definition $definition, Scope $scope, array $data, string $prefix, array $visibility): stdClass
    {
        $view = new stdClass;

        foreach ($scope->elements as $key => $element) {
            $pointer = JsonPointer::append($prefix, $key);
            $value = $data[$key] ?? null;

            $view->{$key} = match (true) {
                ($visibility[$pointer] ?? true) === false => null,
                $element->isRepeater() => self::rows($definition, $element, $value, $pointer, $visibility),
                default => ElementValidator::isEmpty($value) || TypeChecker::passes($element, $value) ? $value : null,
            };
        }

        return $view;
    }

    /**
     * @param  array<string, bool>  $visibility
     * @return list<stdClass|null>|null
     */
    private static function rows(Definition $definition, Element $repeater, mixed $value, string $pointer, array $visibility): ?array
    {
        $rowScope = $definition->scope($repeater->rowScopeId());

        if (! is_array($value) || $rowScope === null) {
            return null;
        }

        $rows = [];
        foreach (array_values($value) as $index => $row) {
            $rows[] = is_array($row)
                ? self::scopeView($definition, $rowScope, $row, JsonPointer::append($pointer, $index), $visibility)
                : null;
        }

        return $rows;
    }

    private static function copyInto(stdClass $target, stdClass $source): void
    {
        foreach (get_object_vars($source) as $key => $value) {
            $target->{(string) $key} = $value;
        }
    }
}
