<?php

declare(strict_types=1);

namespace App\Domain\Forms\Schema;

use FormSchema\Json\Json;
use stdClass;

/**
 * Compares two definitions element by element (matched by `id`) and reports additions, removals,
 * property changes and moves in human terms (BUILD_PROMPT §7.6, §8.10).
 */
final class DefinitionDiff
{
    private const array CHILD_PROPERTIES = ['elements', 'columns'];

    /** @return list<DiffEntry> */
    public static function compare(stdClass $from, stdClass $to): array
    {
        $before = self::index($from);
        $after = self::index($to);
        $entries = [];

        foreach ($after as $id => $node) {
            if (! isset($before[$id])) {
                $entries[] = self::entry(DiffEntry::ADDED, $id, $node['element']);

                continue;
            }

            $changed = self::changedProperties($before[$id]['element'], $node['element']);
            if ($changed !== []) {
                $entries[] = self::entry(DiffEntry::CHANGED, $id, $node['element'], $changed);
            } elseif ($before[$id]['parent'] !== $node['parent'] || $before[$id]['position'] !== $node['position']) {
                $entries[] = self::entry(DiffEntry::MOVED, $id, $node['element']);
            }
        }

        foreach ($before as $id => $node) {
            if (! isset($after[$id])) {
                $entries[] = self::entry(DiffEntry::REMOVED, $id, $node['element']);
            }
        }

        return $entries;
    }

    /** @return array<string, array{element: stdClass, parent: string, position: int}> */
    private static function index(stdClass $definition): array
    {
        $index = [];
        $pages = is_array($definition->pages ?? null) ? $definition->pages : [];
        foreach ($pages as $page) {
            if ($page instanceof stdClass) {
                self::indexChildren($page, 'page:'.self::str($page->key ?? ''), $index);
            }
        }

        return $index;
    }

    /** @param  array<string, array{element: stdClass, parent: string, position: int}>  $index */
    private static function indexChildren(stdClass $container, string $parent, array &$index): void
    {
        $children = is_array($container->elements ?? null) ? $container->elements : [];
        foreach (array_values($children) as $position => $element) {
            if (! $element instanceof stdClass || ! is_string($element->id ?? null)) {
                continue;
            }
            $index[$element->id] = ['element' => $element, 'parent' => $parent, 'position' => $position];
            self::indexChildren($element, $element->id, $index);
            $columns = is_array($element->columns ?? null) ? $element->columns : [];
            foreach (array_values($columns) as $i => $column) {
                if ($column instanceof stdClass) {
                    self::indexChildren($column, $element->id.'#'.$i, $index);
                }
            }
        }
    }

    /** @return list<string> */
    private static function changedProperties(stdClass $a, stdClass $b): array
    {
        $names = array_unique([...array_keys(get_object_vars($a)), ...array_keys(get_object_vars($b))]);
        $changed = [];
        foreach ($names as $name) {
            if (in_array($name, self::CHILD_PROPERTIES, true)) {
                continue;
            }
            $left = property_exists($a, $name) ? Json::canonical($a->{$name}) : null;
            $right = property_exists($b, $name) ? Json::canonical($b->{$name}) : null;
            if ($left !== $right) {
                $changed[] = (string) $name;
            }
        }
        sort($changed);

        return $changed;
    }

    /** @param  list<string>  $properties */
    private static function entry(string $change, string $id, stdClass $element, array $properties = []): DiffEntry
    {
        return new DiffEntry(
            $change,
            $id,
            self::str($element->key ?? ''),
            self::str($element->type ?? ''),
            self::str($element->label ?? ($element->text ?? '')),
            $properties,
        );
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
