<?php

declare(strict_types=1);

namespace FormSchema\Evaluation;

use FormSchema\Definition\Definition;
use FormSchema\Definition\Scope;
use stdClass;

/**
 * Immutable helpers for the internal data tree: scope objects are PHP arrays keyed by data key,
 * repeater values are lists of row arrays, all other values are JSON trees.
 *
 * A row path is a list of [repeater key, row index] pairs from the root to a row.
 */
final class DataTree
{
    /**
     * @param  array<array-key, mixed>  $root
     * @param  list<array{string, int}>  $rowPath
     * @return array<array-key, mixed>|null
     */
    public static function row(array $root, array $rowPath): ?array
    {
        $current = $root;

        foreach ($rowPath as [$key, $index]) {
            $rows = $current[$key] ?? null;
            $row = is_array($rows) ? ($rows[$index] ?? null) : null;

            if (! is_array($row)) {
                return null;
            }

            $current = $row;
        }

        return $current;
    }

    /**
     * @param  array<array-key, mixed>  $root
     * @param  list<array{string, int}>  $rowPath
     */
    public static function has(array $root, array $rowPath, string $key): bool
    {
        $row = self::row($root, $rowPath);

        return $row !== null && array_key_exists($key, $row);
    }

    /**
     * @param  array<array-key, mixed>  $root
     * @param  list<array{string, int}>  $rowPath
     */
    public static function get(array $root, array $rowPath, string $key): mixed
    {
        return self::row($root, $rowPath)[$key] ?? null;
    }

    /**
     * @param  array<array-key, mixed>  $root
     * @param  list<array{string, int}>  $rowPath
     * @return array<array-key, mixed>
     */
    public static function set(array $root, array $rowPath, string $key, mixed $value): array
    {
        return self::update($root, $rowPath, static function (array $row) use ($key, $value): array {
            $row[$key] = $value;

            return $row;
        });
    }

    /**
     * @param  array<array-key, mixed>  $root
     * @param  list<array{string, int}>  $rowPath
     * @return array<array-key, mixed>
     */
    public static function remove(array $root, array $rowPath, string $key): array
    {
        return self::update($root, $rowPath, static function (array $row) use ($key): array {
            unset($row[$key]);

            return $row;
        });
    }

    /**
     * Converts a scope array into its JSON object, rows recursively.
     *
     * @param  array<array-key, mixed>  $values
     */
    public static function toJson(Definition $definition, Scope $scope, array $values): stdClass
    {
        $object = new stdClass;

        foreach ($values as $key => $value) {
            $element = $scope->element((string) $key);
            $rowScope = $element !== null && $element->isRepeater() ? $definition->scope($element->rowScopeId()) : null;

            $object->{(string) $key} = $rowScope !== null && is_array($value)
                ? array_map(
                    static fn (mixed $row): mixed => is_array($row) ? self::toJson($definition, $rowScope, $row) : $row,
                    array_values($value),
                )
                : $value;
        }

        return $object;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<array{string, int}>  $rowPath
     * @param  callable(array<array-key, mixed>): array<array-key, mixed>  $change
     * @return array<array-key, mixed>
     */
    private static function update(array $node, array $rowPath, callable $change): array
    {
        if ($rowPath === []) {
            return $change($node);
        }

        [$key, $index] = $rowPath[0];
        $rows = $node[$key] ?? null;

        if (! is_array($rows) || ! isset($rows[$index]) || ! is_array($rows[$index])) {
            return $node;
        }

        $rows[$index] = self::update($rows[$index], array_slice($rowPath, 1), $change);
        $node[$key] = $rows;

        return $node;
    }
}
