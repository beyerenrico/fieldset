<?php

declare(strict_types=1);

namespace App\Domain\Forms\Schema;

use Fieldset\Json\Json;
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
        $entries = [...self::formChanges($from, $to), ...self::pageChanges($from, $to)];
        $before = self::index($from);
        $after = self::index($to);

        foreach ($after as $rawId => $node) {
            $id = (string) $rawId; // numeric-string keys become ints in PHP arrays
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

        foreach ($before as $rawId => $node) {
            if (! isset($after[$rawId])) {
                $entries[] = self::entry(DiffEntry::REMOVED, (string) $rawId, $node['element']);
            }
        }

        return $entries;
    }

    /** @return list<DiffEntry> settings and other definition-level properties */
    private static function formChanges(stdClass $from, stdClass $to): array
    {
        $changed = self::changedProperties($from, $to, ['pages']);

        return $changed === [] ? [] : [new DiffEntry(DiffEntry::CHANGED, 'definition', self::str($to->key ?? ''), 'form', self::str($to->title ?? ''), $changed)];
    }

    /** @return list<DiffEntry> pages matched by id (or key when a page has no id) */
    private static function pageChanges(stdClass $from, stdClass $to): array
    {
        $before = self::pages($from);
        $after = self::pages($to);
        $entries = [];
        foreach ($after as $id => [$page, $position]) {
            $label = self::str($page->title ?? '');
            if (! isset($before[$id])) {
                $entries[] = new DiffEntry(DiffEntry::ADDED, $id, self::str($page->key ?? ''), 'page', $label);

                continue;
            }
            $changed = self::changedProperties($before[$id][0], $page, ['elements']);
            if ($changed !== []) {
                $entries[] = new DiffEntry(DiffEntry::CHANGED, $id, self::str($page->key ?? ''), 'page', $label, $changed);
            } elseif ($before[$id][1] !== $position) {
                $entries[] = new DiffEntry(DiffEntry::MOVED, $id, self::str($page->key ?? ''), 'page', $label);
            }
        }
        foreach ($before as $id => [$page]) {
            if (! isset($after[$id])) {
                $entries[] = new DiffEntry(DiffEntry::REMOVED, $id, self::str($page->key ?? ''), 'page', self::str($page->title ?? ''));
            }
        }

        return $entries;
    }

    /** @return array<string, array{0: stdClass, 1: int}> */
    private static function pages(stdClass $definition): array
    {
        $pages = [];
        foreach (array_values(is_array($definition->pages ?? null) ? $definition->pages : []) as $position => $page) {
            if ($page instanceof stdClass) {
                $pages[self::pageId($page)] = [$page, $position];
            }
        }

        return $pages;
    }

    private static function pageId(stdClass $page): string
    {
        return is_string($page->id ?? null) ? $page->id : 'page:'.self::str($page->key ?? '');
    }

    /** @return array<string, array{element: stdClass, parent: string, position: int}> */
    private static function index(stdClass $definition): array
    {
        $index = [];
        $pages = is_array($definition->pages ?? null) ? $definition->pages : [];
        foreach ($pages as $page) {
            if ($page instanceof stdClass) {
                self::indexChildren($page, self::pageId($page), $index);
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
            $id = $element->id;
            $index[$id] = ['element' => $element, 'parent' => $parent, 'position' => $position];
            self::indexChildren($element, $id, $index);
            $columns = is_array($element->columns ?? null) ? $element->columns : [];
            foreach (array_values($columns) as $i => $column) {
                if ($column instanceof stdClass) {
                    self::indexChildren($column, $id.'#'.$i, $index);
                }
            }
        }
    }

    /**
     * @param  list<string>  $ignore
     * @return list<string>
     */
    private static function changedProperties(stdClass $a, stdClass $b, array $ignore = self::CHILD_PROPERTIES): array
    {
        $names = array_unique([...array_keys(get_object_vars($a)), ...array_keys(get_object_vars($b))]);
        $changed = [];
        foreach ($names as $name) {
            if (in_array($name, $ignore, true)) {
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
