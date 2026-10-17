<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Retention;

use stdClass;

/**
 * The anonymized copy of submission data (ADR 0039). Values that identify people or carry free text are
 * removed; values that reports aggregate stay: numbers, money, ratings, dates and times, choices, yes/no and
 * calculated numbers. Kept elements with a personal semantic role (customer.*, site.*, payment.*) are removed
 * too. Repeaters keep their rows (row counts stay meaningful) with the kept values only. Keys the definition
 * does not know are dropped. Returns a new object; the input is never changed.
 */
final class SubmissionAnonymizer
{
    private const array LAYOUT = ['section', 'columns', 'heading', 'paragraph', 'divider', 'image', 'page_break'];

    /** Element types whose values are kept. */
    public const array KEPT_TYPES = [
        'number', 'money', 'rating', 'date', 'time', 'datetime', 'select', 'multiselect', 'radio', 'checkbox', 'toggle',
    ];

    private const array PERSONAL_ROLE_PREFIXES = ['customer.', 'site.', 'payment.'];

    public static function data(stdClass $definition, stdClass $data): stdClass
    {
        $anonymized = new stdClass;
        foreach (is_array($definition->pages ?? null) ? $definition->pages : [] as $page) {
            if ($page instanceof stdClass) {
                self::scope(self::list($page->elements ?? null), $data, $anonymized);
            }
        }

        return $anonymized;
    }

    /**
     * The read-model keys (`submission_values.key`, repeater children as `repeater.child`) whose values the
     * anonymized copy keeps.
     *
     * @return list<string>
     */
    public static function keptKeys(stdClass $definition): array
    {
        $keys = [];
        foreach (is_array($definition->pages ?? null) ? $definition->pages : [] as $page) {
            if ($page instanceof stdClass) {
                self::collectKeys(self::list($page->elements ?? null), '', $keys);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  list<mixed>  $elements
     * @param  list<string>  $keys
     */
    private static function collectKeys(array $elements, string $prefix, array &$keys): void
    {
        foreach ($elements as $element) {
            if (! $element instanceof stdClass || ! is_string($element->type ?? null)) {
                continue;
            }
            if (in_array($element->type, self::LAYOUT, true)) {
                self::collectKeys(self::list($element->elements ?? null), $prefix, $keys);
                foreach (self::list($element->columns ?? null) as $column) {
                    if ($column instanceof stdClass) {
                        self::collectKeys(self::list($column->elements ?? null), $prefix, $keys);
                    }
                }
            } elseif (is_string($element->key ?? null) && $element->type === 'repeater') {
                self::collectKeys(self::list($element->elements ?? null), $prefix.$element->key.'.', $keys);
            } elseif (is_string($element->key ?? null) && self::keeps($element)) {
                $keys[] = mb_substr($prefix.$element->key, 0, 64);
            }
        }
    }

    /** @param  list<mixed>  $elements */
    private static function scope(array $elements, stdClass $data, stdClass $out): void
    {
        foreach ($elements as $element) {
            if (! $element instanceof stdClass || ! is_string($element->type ?? null)) {
                continue;
            }
            if (in_array($element->type, self::LAYOUT, true)) {
                self::layout($element, $data, $out);

                continue;
            }
            $key = $element->key ?? null;
            if (! is_string($key) || ! property_exists($data, $key)) {
                continue;
            }
            if ($element->type === 'repeater') {
                $out->{$key} = self::rows(self::list($element->elements ?? null), $data->{$key});
            } elseif (self::keeps($element)) {
                $out->{$key} = $data->{$key};
            }
        }
    }

    private static function layout(stdClass $element, stdClass $data, stdClass $out): void
    {
        self::scope(self::list($element->elements ?? null), $data, $out);
        foreach (self::list($element->columns ?? null) as $column) {
            if ($column instanceof stdClass) {
                self::scope(self::list($column->elements ?? null), $data, $out);
            }
        }
    }

    /**
     * @param  list<mixed>  $elements
     * @return list<stdClass>
     */
    private static function rows(array $elements, mixed $rows): array
    {
        $anonymized = [];
        foreach (is_array($rows) ? array_values($rows) : [] as $row) {
            $copy = new stdClass;
            if ($row instanceof stdClass) {
                self::scope($elements, $row, $copy);
            }
            $anonymized[] = $copy;
        }

        return $anonymized;
    }

    private static function keeps(stdClass $element): bool
    {
        $role = is_string($element->role ?? null) ? $element->role : '';
        foreach (self::PERSONAL_ROLE_PREFIXES as $prefix) {
            if (str_starts_with($role, $prefix)) {
                return false;
            }
        }

        return in_array($element->type, self::KEPT_TYPES, true)
            || ($element->type === 'calculated' && ($element->format ?? 'number') !== 'text');
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
