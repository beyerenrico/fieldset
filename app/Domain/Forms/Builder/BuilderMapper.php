<?php

declare(strict_types=1);

namespace App\Domain\Forms\Builder;

use Fieldset\Json\Json;
use stdClass;

/**
 * Converts between a canonical Form Definition and Builder v1 form state, losslessly
 * (BUILD_PROMPT §8.2). Every page, column, option and element carries `_source`: its original JSON
 * without children. NodeMapper maps the single nodes (unchanged values verbatim, edited values through
 * FieldCodec, unknown and `x-*` properties untouched); this class walks the tree.
 * A repeated element id means the block was cloned: it gets a new id and a suffixed key.
 */
final class BuilderMapper
{
    private const array CONTAINERS = ['repeater', 'section'];

    /** @var array<string, true> */
    private array $seenIds = [];

    /** @param  list<string>  $lockedIds  element ids that appear in a published version (keys locked) */
    public function __construct(private readonly array $lockedIds = []) {}

    /** @return array<string, mixed> */
    public function toState(stdClass $definition): array
    {
        $nodes = $this->nodes(KeyTypes::fromDefinition($definition));
        $state = $nodes->settingsToState($definition);
        $state['pages'] = array_map(fn (mixed $page): array => $this->pageToState($nodes, $page), $this->list($definition->pages ?? []));

        return $state;
    }

    /** @param  array<string, mixed>  $state */
    public function toDefinition(array $state): stdClass
    {
        $this->seenIds = [];
        $nodes = $this->nodes(KeyTypes::fromState($state));
        $definition = $nodes->settingsFromState($state);
        $definition->pages = array_map(fn (mixed $page): stdClass => $this->pageFromState($nodes, $page), array_values($this->arr($state['pages'] ?? [])));

        return $definition;
    }

    /** @return array<string, mixed> */
    private function pageToState(NodeMapper $nodes, mixed $page): array
    {
        $page = $page instanceof stdClass ? $page : new stdClass;

        return $nodes->pageToState($page) + ['elements' => $this->blocksToState($nodes, $page->elements ?? [])];
    }

    private function pageFromState(NodeMapper $nodes, mixed $state): stdClass
    {
        $state = $this->arr($state);
        $page = $nodes->pageFromState($state);
        $page->elements = $this->blocksFromState($nodes, $state['elements'] ?? []);

        return $page;
    }

    /** @return list<array{type: string, data: array<string, mixed>}> */
    private function blocksToState(NodeMapper $nodes, mixed $elements): array
    {
        $blocks = [];
        foreach ($this->list($elements) as $element) {
            if (! $element instanceof stdClass) {
                continue;
            }
            $type = $this->str($element->type ?? null);
            $data = $nodes->elementToState($element);
            if (in_array($type, self::CONTAINERS, true)) {
                $data['elements'] = $this->blocksToState($nodes, $element->elements ?? []);
            }
            if ($type === 'columns') {
                $data['columns'] = array_map(fn (mixed $column): array => [
                    '_source' => Json::encode(NodeMapper::withoutChildren(NodeMapper::PAGE, $column instanceof stdClass ? $column : new stdClass)),
                    'elements' => $this->blocksToState($nodes, $column instanceof stdClass ? ($column->elements ?? []) : []),
                ], $this->list($element->columns ?? []));
            }
            $blocks[] = ['type' => $type, 'data' => $data];
        }

        return $blocks;
    }

    /** @return list<stdClass> */
    private function blocksFromState(NodeMapper $nodes, mixed $blocks): array
    {
        $elements = [];
        foreach (array_values($this->arr($blocks)) as $block) {
            $block = $this->arr($block);
            $type = $this->str($block['type'] ?? null);
            $data = $this->arr($block['data'] ?? []);
            $id = $this->str($data['id'] ?? null);
            $element = $nodes->elementFromState($type, $data, clone: $id !== '' && isset($this->seenIds[$id]));
            $this->seenIds[$element->id] = true;
            if (in_array($type, self::CONTAINERS, true)) {
                $element->elements = $this->blocksFromState($nodes, $data['elements'] ?? []);
            }
            if ($type === 'columns') {
                $element->columns = array_map(function (mixed $column) use ($nodes): stdClass {
                    $column = $this->arr($column);
                    $object = FieldCodec::decodeObject($column['_source'] ?? null);
                    $object->elements = $this->blocksFromState($nodes, $column['elements'] ?? []);

                    return $object;
                }, array_values($this->arr($data['columns'] ?? [])));
            }
            $elements[] = $element;
        }

        return $elements;
    }

    /** @param  array<string, string>  $types */
    private function nodes(array $types): NodeMapper
    {
        return new NodeMapper($types, $this->lockedIds);
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /** @return array<array-key, mixed> */
    private function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
