<?php

declare(strict_types=1);

namespace FormSchema\Lint\Rules;

use FormSchema\Definition\Definition;
use FormSchema\Definition\Element;
use FormSchema\Lint\LintFinding;
use FormSchema\Lint\LintRule;
use stdClass;

/**
 * duplicate_key, duplicate_page_key, duplicate_id, duplicate_option_value — reported at the
 * second and later occurrences.
 */
final class DuplicateRule implements LintRule
{
    public function check(Definition $definition, array $published): array
    {
        return [
            ...self::pageKeys($definition),
            ...self::keys($definition),
            ...self::ids($definition),
            ...self::options($definition),
        ];
    }

    /**
     * @return list<LintFinding>
     */
    private static function pageKeys(Definition $definition): array
    {
        $seen = [];
        $findings = [];

        foreach ($definition->pages as $page) {
            $key = $page->get('key');

            if (! is_string($key)) {
                continue;
            }

            if (isset($seen[$key])) {
                $findings[] = LintFinding::error('duplicate_page_key', $page->path, ['key' => $key]);
            }

            $seen[$key] = true;
        }

        return $findings;
    }

    /**
     * Keys of all elements (layout included) must be unique per scope.
     *
     * @return list<LintFinding>
     */
    private static function keys(Definition $definition): array
    {
        $seen = [];
        $findings = [];

        foreach ($definition->elements as $element) {
            if ($element->key === '') {
                continue;
            }

            if (isset($seen[$element->scopeId][$element->key])) {
                $findings[] = LintFinding::error('duplicate_key', $element->path, ['key' => $element->key]);
            }

            $seen[$element->scopeId][$element->key] = true;
        }

        return $findings;
    }

    /**
     * @return list<LintFinding>
     */
    private static function ids(Definition $definition): array
    {
        $seen = [];
        $findings = [];

        foreach ($definition->elements as $element) {
            $id = strtolower($element->id);

            if ($id !== '' && isset($seen[$id])) {
                $findings[] = LintFinding::error('duplicate_id', $element->path, ['id' => $id]);
            }

            $seen[$id] = true;
        }

        return $findings;
    }

    /**
     * @return list<LintFinding>
     */
    private static function options(Definition $definition): array
    {
        $findings = [];

        foreach ($definition->elements as $element) {
            array_push($findings, ...self::optionsOf($element));
        }

        return $findings;
    }

    /**
     * @return list<LintFinding>
     */
    private static function optionsOf(Element $element): array
    {
        $options = $element->get('options');
        $seen = [];
        $findings = [];

        foreach (is_array($options) ? array_values($options) : [] as $index => $option) {
            $value = $option instanceof stdClass ? ($option->value ?? null) : null;

            if (! is_string($value)) {
                continue;
            }

            if (isset($seen[$value])) {
                $findings[] = LintFinding::error('duplicate_option_value', $element->path.'/options/'.$index, ['value' => $value]);
            }

            $seen[$value] = true;
        }

        return $findings;
    }
}
