<?php

declare(strict_types=1);

namespace App\Domain\Forms\Schema;

use Closure;
use FormSchema\Json\Json;
use stdClass;

/**
 * Pure helpers over definition trees (stdClass). Inputs are never mutated: every method works on a
 * deep copy and returns it.
 */
final class DefinitionTools
{
    /**
     * Gives pages, columns and elements fresh ids (used when duplicating forms or cloning blocks).
     *
     * @param  Closure(): string  $newId
     */
    public static function regenerateIds(stdClass $definition, Closure $newId): stdClass
    {
        $copy = self::copy($definition);
        self::walk($copy, static function (stdClass $node) use ($newId): void {
            if (property_exists($node, 'id')) {
                $node->id = $newId();
            }
        });

        return $copy;
    }

    /** @return list<stdClass> every element (any depth), in document order */
    public static function elements(stdClass $definition): array
    {
        $found = [];
        $pages = is_array($definition->pages ?? null) ? $definition->pages : [];
        foreach ($pages as $page) {
            if ($page instanceof stdClass) {
                self::collect($page, $found);
            }
        }

        return $found;
    }

    public static function copy(stdClass $value): stdClass
    {
        $copy = Json::decode(Json::encode($value));

        return $copy instanceof stdClass ? $copy : new stdClass;
    }

    /** @param  Closure(stdClass): void  $visit */
    private static function walk(mixed $node, Closure $visit): void
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                self::walk($child, $visit);
            }

            return;
        }

        if (! $node instanceof stdClass) {
            return;
        }

        $isStructural = property_exists($node, 'elements') || property_exists($node, 'type') || property_exists($node, 'key');
        if ($isStructural) {
            $visit($node);
        }

        foreach (['pages', 'elements', 'columns'] as $child) {
            if (property_exists($node, $child)) {
                self::walk($node->{$child}, $visit);
            }
        }
    }

    /** @param  list<stdClass>  $found */
    private static function collect(stdClass $container, array &$found): void
    {
        $children = is_array($container->elements ?? null) ? $container->elements : [];
        foreach ($children as $element) {
            if (! $element instanceof stdClass) {
                continue;
            }
            $found[] = $element;
            self::collect($element, $found);
            foreach (is_array($element->columns ?? null) ? $element->columns : [] as $column) {
                if ($column instanceof stdClass) {
                    self::collect($column, $found);
                }
            }
        }
    }
}
