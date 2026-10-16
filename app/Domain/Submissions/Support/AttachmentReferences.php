<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

use stdClass;

/** Collects the attachment UUIDs referenced by photo, file and signature values (incl. repeater rows). */
final class AttachmentReferences
{
    /** @return list<string> */
    public static function collect(stdClass $definition, stdClass $data): array
    {
        $found = [];
        foreach (is_array($definition->pages ?? null) ? $definition->pages : [] as $page) {
            if ($page instanceof stdClass) {
                self::scope(is_array($page->elements ?? null) ? $page->elements : [], $data, $found);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param  array<array-key, mixed>  $elements
     * @param  list<string>  $found
     */
    private static function scope(array $elements, stdClass $scope, array &$found): void
    {
        foreach ($elements as $element) {
            if (! $element instanceof stdClass) {
                continue;
            }
            $type = is_string($element->type ?? null) ? $element->type : '';
            $key = is_string($element->key ?? null) ? $element->key : '';
            $value = $key !== '' && property_exists($scope, $key) ? $scope->{$key} : null;

            match (true) {
                $type === 'repeater' => self::rows($element, $value, $found),
                in_array($type, ['photo', 'file'], true) => self::ids($value, $found),
                $type === 'signature' => $value instanceof stdClass ? self::ids($value->attachment ?? null, $found) : null,
                default => null,
            };

            self::scope(is_array($element->elements ?? null) && $type !== 'repeater' ? $element->elements : [], $scope, $found);
            foreach (is_array($element->columns ?? null) ? $element->columns : [] as $column) {
                if ($column instanceof stdClass) {
                    self::scope(is_array($column->elements ?? null) ? $column->elements : [], $scope, $found);
                }
            }
        }
    }

    /** @param  list<string>  $found */
    private static function rows(stdClass $repeater, mixed $rows, array &$found): void
    {
        foreach (is_array($rows) ? $rows : [] as $row) {
            if ($row instanceof stdClass) {
                self::scope(is_array($repeater->elements ?? null) ? $repeater->elements : [], $row, $found);
            }
        }
    }

    /** @param  list<string>  $found */
    private static function ids(mixed $value, array &$found): void
    {
        foreach (is_array($value) ? $value : [$value] as $id) {
            if (is_string($id) && $id !== '') {
                $found[] = strtolower($id);
            }
        }
    }
}
