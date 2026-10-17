<?php

declare(strict_types=1);

namespace App\Domain\Forms\Builder;

use stdClass;

/**
 * Clones one Builder v1 block (with all nested blocks) through ElementCloner: fresh ids, `_copy`,
 * `_copy2`, … keys that are unique among the given keys, and references inside the clone rewritten to
 * the copied keys (BUILD_PROMPT §8.2). Runs server-side in the Builder clone action, so Livewire state
 * stays consistent.
 */
final class BlockCloner
{
    /**
     * @param  array<string, mixed>  $block
     * @param  list<string>  $scopeKeys  keys already used in the form (a second copy then gets `_copy2`)
     * @return array<string, mixed>
     */
    public static function clone(array $block, array $scopeKeys = []): array
    {
        $mapper = new BuilderMapper;
        $definition = $mapper->toDefinition(['pages' => [['key' => 'p', 'title' => 'p', 'elements' => [$block]]]]);
        $element = $definition->pages[0]->elements[0] ?? null;
        if (! $element instanceof stdClass) {
            return $block;
        }

        $clone = ElementCloner::clone($element, $scopeKeys);

        $state = $mapper->toState((object) ['pages' => [(object) ['key' => 'p', 'title' => 'p', 'elements' => [$clone]]]]);
        $pages = is_array($state['pages'] ?? null) ? $state['pages'] : [];
        $first = is_array($pages[0] ?? null) ? $pages[0] : [];
        $elements = is_array($first['elements'] ?? null) ? $first['elements'] : [];

        return is_array($elements[0] ?? null) ? $elements[0] : $block;
    }
}
