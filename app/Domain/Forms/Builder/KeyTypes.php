<?php

declare(strict_types=1);

namespace App\Domain\Forms\Builder;

use App\Domain\Forms\Schema\DefinitionTools;
use stdClass;

/** key => element type maps, used to compile logic rules with the right (decimal or built-in) operators. */
final class KeyTypes
{
    /** @return array<string, string> */
    public static function fromDefinition(stdClass $definition): array
    {
        $types = [];
        foreach (DefinitionTools::elements($definition) as $element) {
            if (is_string($element->key ?? null) && is_string($element->type ?? null)) {
                $types[$element->key] = self::effective($element->type, is_string($element->format ?? null) ? $element->format : null);
            }
        }

        return $types;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, string>
     */
    public static function fromState(array $state): array
    {
        $types = [];
        foreach (is_array($state['pages'] ?? null) ? $state['pages'] : [] as $page) {
            self::collect(is_array($page) ? ($page['elements'] ?? []) : [], $types);
        }

        return $types;
    }

    /** @param  array<string, string>  $types */
    private static function collect(mixed $blocks, array &$types): void
    {
        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null) || ! is_array($block['data'] ?? null)) {
                continue;
            }
            $data = $block['data'];
            if (is_string($data['key'] ?? null)) {
                $types[$data['key']] = self::effective($block['type'], is_string($data['format'] ?? null) ? $data['format'] : null);
            }
            self::collect($data['elements'] ?? [], $types);
            foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $column) {
                self::collect(is_array($column) ? ($column['elements'] ?? []) : [], $types);
            }
        }
    }

    /** A text-format calculated value is compared like text, numeric ones like numbers. */
    private static function effective(string $type, ?string $format): string
    {
        return $type === 'calculated' && $format === 'text' ? 'text' : $type;
    }
}
