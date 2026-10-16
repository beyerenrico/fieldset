<?php

declare(strict_types=1);

namespace FormSchema\Lint\Rules;

use FormSchema\Definition\Definition;
use FormSchema\Definition\Element;
use FormSchema\Lint\LintFinding;
use FormSchema\Lint\LintRule;

/**
 * key_locked (an id published with a different key) and key_type_changed (a key published
 * with a different type in the same scope) against earlier published versions (spec §6).
 */
final class HistoryRule implements LintRule
{
    public function check(Definition $definition, array $published): array
    {
        $publishedKeys = [];
        $publishedTypes = [];

        foreach ($published as $version) {
            foreach ($version->elements as $element) {
                if ($element->id !== '') {
                    $publishedKeys[strtolower($element->id)][$element->key] = true;
                }

                $publishedTypes[self::scopedKey($version, $element)][$element->type] = true;
            }
        }

        $findings = [];
        foreach ($definition->elements as $element) {
            array_push($findings, ...self::lock($element, $publishedKeys[strtolower($element->id)] ?? []));
            array_push($findings, ...self::type($element, $publishedTypes[self::scopedKey($definition, $element)] ?? []));
        }

        return $findings;
    }

    /**
     * @param  array<string, true>  $keys  keys the element id was published with
     * @return list<LintFinding>
     */
    private static function lock(Element $element, array $keys): array
    {
        $previous = array_map('strval', array_keys($keys));

        if ($element->id === '' || $previous === [] || $previous === [$element->key]) {
            return [];
        }

        return [LintFinding::error('key_locked', $element->path.'/key', ['key' => $element->key])];
    }

    /**
     * @param  array<string, true>  $types  types the scoped key was published with
     * @return list<LintFinding>
     */
    private static function type(Element $element, array $types): array
    {
        $previous = array_map('strval', array_keys($types));

        if ($element->key === '' || $previous === [] || $previous === [$element->type]) {
            return [];
        }

        return [LintFinding::error('key_type_changed', $element->path, ['key' => $element->key, 'type' => $element->type])];
    }

    /**
     * Key qualified by the keys of its enclosing repeaters, e.g. `items/qty`.
     */
    private static function scopedKey(Definition $definition, Element $element): string
    {
        $parts = array_map(static fn (Element $repeater): string => $repeater->key, $definition->repeaterChain($element));
        $parts[] = $element->key;

        return implode('/', $parts);
    }
}
