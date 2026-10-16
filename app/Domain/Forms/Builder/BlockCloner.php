<?php

declare(strict_types=1);

namespace App\Domain\Forms\Builder;

use App\Domain\Forms\Schema\DefinitionTools;
use App\Domain\Forms\Schema\KeyRewriter;
use App\Domain\Forms\Support\BlankDefinition;
use stdClass;

/**
 * Clones one Builder block (with all nested blocks): fresh ids, `_copy` key suffixes, and references
 * inside the clone rewritten to the copied keys (BUILD_PROMPT §8.2 "cloning regenerates id and
 * suffixes key"). Runs server-side in the Builder clone action, so Livewire state stays consistent.
 */
final class BlockCloner
{
    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public static function clone(array $block): array
    {
        $mapper = new BuilderMapper;
        $definition = $mapper->toDefinition(['pages' => [['key' => 'p', 'title' => 'p', 'elements' => [$block]]]]);
        $element = $definition->pages[0]->elements[0] ?? null;
        if (! $element instanceof stdClass) {
            return $block;
        }

        $clone = DefinitionTools::copy($element);
        $renames = [];
        $nodes = DefinitionTools::elements((object) ['pages' => [(object) ['elements' => [$clone]]]]); // clone + descendants
        foreach ($nodes as $node) {
            if (! property_exists($node, 'id')) {
                continue;
            }
            $node->id = BlankDefinition::id();
            if (is_string($node->key ?? null)) {
                $new = mb_substr($node->key, 0, 59).'_copy';
                $renames[$node->key] = $new;
                $node->key = $new;
            }
        }
        foreach ($nodes as $node) {
            KeyRewriter::element($node, $renames);
        }

        $state = $mapper->toState((object) ['pages' => [(object) ['key' => 'p', 'title' => 'p', 'elements' => [$clone]]]]);
        $pages = is_array($state['pages'] ?? null) ? $state['pages'] : [];
        $first = is_array($pages[0] ?? null) ? $pages[0] : [];
        $elements = is_array($first['elements'] ?? null) ? $first['elements'] : [];

        return is_array($elements[0] ?? null) ? $elements[0] : $block;
    }
}
