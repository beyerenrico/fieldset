<?php

declare(strict_types=1);

namespace Fieldset\Definition;

use stdClass;

/**
 * Builds the element tree, the pre-order element list and the scopes of a definition.
 *
 * Parsing is tolerant: malformed parts are skipped so the linter can still report on the rest.
 */
final class DefinitionParser
{
    /**
     * @return list<Page>
     */
    public static function pages(stdClass $raw): array
    {
        $pages = [];

        foreach (self::list($raw->pages ?? null) as $index => $page) {
            if (! $page instanceof stdClass) {
                continue;
            }

            $path = '/pages/'.$index;
            $pages[] = new Page(
                $page,
                $index,
                self::string($page, 'key'),
                self::string($page, 'title'),
                $path,
                self::elements($page->elements ?? null, $path.'/elements', '', $index, null),
            );
        }

        return $pages;
    }

    /**
     * @param  list<Page>  $pages
     * @return list<Element> every element in pre-order (pages in order)
     */
    public static function flatten(array $pages): array
    {
        $flat = [];

        foreach ($pages as $page) {
            foreach ($page->elements as $element) {
                array_push($flat, ...self::preOrder($element));
            }
        }

        return $flat;
    }

    /**
     * @param  list<Element>  $elements  pre-order list
     * @return array<string, Scope>
     */
    public static function scopes(array $elements): array
    {
        $scopes = ['' => new Scope('', null, null, self::dataElementsOf($elements, ''))];

        foreach ($elements as $element) {
            if ($element->isRepeater()) {
                $scopes[$element->rowScopeId()] = new Scope(
                    $element->rowScopeId(),
                    $element,
                    $element->scopeId,
                    self::dataElementsOf($elements, $element->rowScopeId()),
                );
            }
        }

        return $scopes;
    }

    /**
     * @return list<Element>
     */
    private static function elements(mixed $list, string $basePath, string $scopeId, int $page, ?string $parent): array
    {
        $elements = [];

        foreach (self::list($list) as $index => $raw) {
            if ($raw instanceof stdClass) {
                $elements[] = self::element($raw, $basePath.'/'.$index, $scopeId, $page, $parent);
            }
        }

        return $elements;
    }

    private static function element(stdClass $raw, string $path, string $scopeId, int $page, ?string $parent): Element
    {
        $type = self::string($raw, 'type');

        $children = match ($type) {
            'section' => self::elements($raw->elements ?? null, $path.'/elements', $scopeId, $page, $path),
            'repeater' => self::elements($raw->elements ?? null, $path.'/elements', $path, $page, $path),
            'columns' => self::columnChildren($raw, $path, $scopeId, $page),
            default => [],
        };

        return new Element(
            $raw, self::string($raw, 'id'), $type, self::string($raw, 'key'), $path, $scopeId, $page, $parent, $children,
        );
    }

    /**
     * @return list<Element>
     */
    private static function columnChildren(stdClass $raw, string $path, string $scopeId, int $page): array
    {
        $children = [];

        foreach (self::list($raw->columns ?? null) as $index => $column) {
            if ($column instanceof stdClass) {
                $base = $path.'/columns/'.$index.'/elements';
                array_push($children, ...self::elements($column->elements ?? null, $base, $scopeId, $page, $path));
            }
        }

        return $children;
    }

    /**
     * @return list<Element>
     */
    private static function preOrder(Element $element): array
    {
        $list = [$element];

        foreach ($element->children as $child) {
            array_push($list, ...self::preOrder($child));
        }

        return $list;
    }

    /**
     * @param  list<Element>  $elements
     * @return array<string, Element>
     */
    private static function dataElementsOf(array $elements, string $scopeId): array
    {
        $map = [];

        foreach ($elements as $element) {
            if ($element->scopeId === $scopeId && $element->isData() && $element->key !== ''
                && ! isset($map[$element->key])) {
                $map[$element->key] = $element;
            }
        }

        return $map;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    private static function string(stdClass $raw, string $property): string
    {
        $value = $raw->{$property} ?? null;

        return is_string($value) ? $value : '';
    }
}
