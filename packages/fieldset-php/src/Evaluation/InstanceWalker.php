<?php

declare(strict_types=1);

namespace Fieldset\Evaluation;

use Fieldset\Definition\Definition;
use Fieldset\Definition\Element;

/**
 * Enumerates element instances in document order (pre-order), rows in index order.
 */
final class InstanceWalker
{
    /**
     * @param  array<array-key, mixed>  $values
     * @return list<Instance>
     */
    public static function walk(Definition $definition, array $values): array
    {
        $instances = [];

        foreach ($definition->pages as $page) {
            foreach ($page->elements as $element) {
                array_push($instances, ...self::visit($element, [], null, $page->index, $values));
            }
        }

        return $instances;
    }

    /**
     * Row paths of every row of the given repeater chain (outermost first).
     *
     * @param  list<Element>  $repeaters
     * @param  array<array-key, mixed>  $values
     * @return list<list<array{string, int}>>
     */
    public static function rowPaths(array $repeaters, array $values): array
    {
        $paths = [[]];

        foreach ($repeaters as $repeater) {
            $next = [];

            foreach ($paths as $path) {
                $rows = DataTree::get($values, $path, $repeater->key);

                foreach (is_array($rows) ? array_keys($rows) : [] as $index) {
                    $next[] = [...$path, [$repeater->key, (int) $index]];
                }
            }

            $paths = $next;
        }

        return $paths;
    }

    /**
     * @param  list<array{string, int}>  $rowPath
     * @param  array<array-key, mixed>  $values
     * @return list<Instance>
     */
    private static function visit(Element $element, array $rowPath, ?string $parent, int $page, array $values): array
    {
        $pointer = Instance::pointerFor($rowPath, $element->key);
        $instances = [new Instance($element, $rowPath, $pointer, $parent, $page)];

        if (! $element->isRepeater()) {
            foreach ($element->children as $child) {
                array_push($instances, ...self::visit($child, $rowPath, $pointer, $page, $values));
            }

            return $instances;
        }

        $rows = DataTree::get($values, $rowPath, $element->key);

        foreach (is_array($rows) ? array_keys($rows) : [] as $index) {
            $childPath = [...$rowPath, [$element->key, (int) $index]];

            foreach ($element->children as $child) {
                array_push($instances, ...self::visit($child, $childPath, $pointer, $page, $values));
            }
        }

        return $instances;
    }
}
