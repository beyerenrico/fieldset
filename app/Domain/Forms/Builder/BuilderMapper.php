<?php

declare(strict_types=1);

namespace App\Domain\Forms\Builder;

use App\Domain\Forms\Builder\FieldSpec as F;
use App\Domain\Forms\Support\BlankDefinition;
use FormSchema\Json\Json;
use stdClass;

/**
 * Converts between a canonical Form Definition and Builder v1 form state, losslessly
 * (BUILD_PROMPT §8.2). Every page, column, option and element carries `_source`: its original JSON
 * without children. Values the user did not change are written back verbatim from `_source`, edited
 * values are converted by FieldCodec, and unknown or `x-*` properties survive untouched.
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
        $codec = $this->codec(KeyTypes::fromDefinition($definition));
        $state = ['_source' => Json::encode($this->without($definition, 'pages'))];
        $state += $this->read($codec, $definition, $this->definitionFields());
        $state['pages'] = array_map(fn (mixed $page): array => $this->pageToState($codec, $page), $this->list($definition->pages ?? []));

        return $state;
    }

    /** @param  array<string, mixed>  $state */
    public function toDefinition(array $state): stdClass
    {
        $this->seenIds = [];
        $codec = $this->codec(KeyTypes::fromState($state));
        $source = FieldCodec::decodeObject($state['_source'] ?? null);
        $definition = $this->apply($codec, $source, $state, $this->definitionFields());
        $this->dropIncompleteNumbering($definition, $source);
        $definition->pages = array_map(fn (mixed $page): stdClass => $this->pageFromState($codec, $page), array_values($this->arr($state['pages'] ?? [])));

        return $definition;
    }

    /** @return array<string, mixed> */
    private function pageToState(FieldCodec $codec, mixed $page): array
    {
        $page = $page instanceof stdClass ? $page : new stdClass;

        return ['_source' => Json::encode($this->without($page, 'elements')), 'id' => $this->str($page->id ?? null)]
            + $this->read($codec, $page, $this->pageFields())
            + ['elements' => $this->blocksToState($codec, $page->elements ?? [])];
    }

    private function pageFromState(FieldCodec $codec, mixed $state): stdClass
    {
        $state = $this->arr($state);
        $page = $this->apply($codec, FieldCodec::decodeObject($state['_source'] ?? null), $state, $this->pageFields());
        $id = $this->str($state['id'] ?? null);
        if ($id !== '' || property_exists($page, 'id')) {
            $page->id = $id !== '' ? $id : BlankDefinition::id();
        }
        $page->elements = $this->blocksFromState($codec, $state['elements'] ?? []);

        return $page;
    }

    /** @return list<array{type: string, data: array<string, mixed>}> */
    private function blocksToState(FieldCodec $codec, mixed $elements): array
    {
        $blocks = [];
        foreach ($this->list($elements) as $element) {
            if (! $element instanceof stdClass) {
                continue;
            }
            $type = $this->str($element->type ?? null);
            $data = ['_source' => Json::encode($this->without($element, 'elements', 'columns')), 'id' => $this->str($element->id ?? null),
                'key' => $this->str($element->key ?? null), 'key_locked' => in_array($element->id ?? null, $this->lockedIds, true)];
            $data += $this->read($codec, $element, ElementFields::for($type));
            if (in_array($type, self::CONTAINERS, true)) {
                $data['elements'] = $this->blocksToState($codec, $element->elements ?? []);
            }
            if ($type === 'columns') {
                $data['columns'] = array_map(fn (mixed $column): array => [
                    '_source' => Json::encode($this->without($column instanceof stdClass ? $column : new stdClass, 'elements')),
                    'elements' => $this->blocksToState($codec, $column instanceof stdClass ? ($column->elements ?? []) : []),
                ], $this->list($element->columns ?? []));
            }
            $blocks[] = ['type' => $type, 'data' => $data];
        }

        return $blocks;
    }

    /** @return list<stdClass> */
    private function blocksFromState(FieldCodec $codec, mixed $blocks): array
    {
        $elements = [];
        foreach (array_values($this->arr($blocks)) as $block) {
            $block = $this->arr($block);
            $type = $this->str($block['type'] ?? null);
            $data = $this->arr($block['data'] ?? []);
            $source = FieldCodec::decodeObject($data['_source'] ?? null);
            $element = $this->apply($codec, $source, $data, ElementFields::for($type));
            $element->type = $type;
            $this->assignIdentity($element, $source, $data);
            if (in_array($type, self::CONTAINERS, true)) {
                $element->elements = $this->blocksFromState($codec, $data['elements'] ?? []);
            }
            if ($type === 'columns') {
                $element->columns = array_map(function (mixed $column) use ($codec): stdClass {
                    $column = $this->arr($column);
                    $object = FieldCodec::decodeObject($column['_source'] ?? null);
                    $object->elements = $this->blocksFromState($codec, $column['elements'] ?? []);

                    return $object;
                }, array_values($this->arr($data['columns'] ?? [])));
            }
            $elements[] = $this->ordered($element);
        }

        return $elements;
    }

    /** @param  array<string, mixed>  $data */
    private function assignIdentity(stdClass $element, stdClass $source, array $data): void
    {
        $id = $this->str($data['id'] ?? null);
        $key = $this->str($data['key'] ?? null);
        $originalKey = $this->str($source->key ?? null);

        if ($id !== '' && in_array($id, $this->lockedIds, true) && $originalKey !== '') {
            $key = $originalKey;
        }

        if ($id === '' || isset($this->seenIds[$id])) {
            // Cloned (or new) block: fresh identity, and a suffixed key for clones.
            $key = $id !== '' && $key !== '' ? mb_substr($key, 0, 59).'_copy' : $key;
            $id = BlankDefinition::id();
        }

        $this->seenIds[$id] = true;
        $element->id = $id;
        $element->key = $key !== '' ? $key : 'field_'.substr(str_replace('-', '', $id), -8);
    }

    /**
     * @param  list<F>  $fields
     * @return array<string, mixed>
     */
    private function read(FieldCodec $codec, stdClass $source, array $fields): array
    {
        $data = [];
        foreach ($fields as $spec) {
            $data[$spec->ui] = $codec->toUi($spec, JsonPath::get($source, $spec->path));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<F>  $fields
     */
    private function apply(FieldCodec $codec, stdClass $source, array $data, array $fields): stdClass
    {
        $target = Json::decode(Json::encode($source));
        $target = $target instanceof stdClass ? $target : new stdClass;

        foreach ($fields as $spec) {
            if (! array_key_exists($spec->ui, $data)) {
                continue;
            }
            $original = JsonPath::get($source, $spec->path);
            $unchanged = FieldCodec::normalize($data[$spec->ui]) === FieldCodec::normalize($codec->toUi($spec, $original));
            $value = $unchanged ? $original : $codec->fromUi($spec, $data[$spec->ui]);

            $value === JsonPath::ABSENT
                ? JsonPath::unset($target, $spec->path, $source)
                : JsonPath::set($target, $spec->path, $value);
        }

        return $target;
    }

    /** @param  array<string, string>  $types */
    private function codec(array $types): FieldCodec
    {
        return new FieldCodec(new LogicCompiler($types), new CalculationCompiler);
    }

    /** @return list<F> */
    private function definitionFields(): array
    {
        return [
            new F('title', ['title'], F::STRING), new F('locale', ['locale'], F::STRING),
            new F('layout', ['settings', 'layout'], F::STRING),
            new F('numbering_format', ['settings', 'numbering', 'format'], F::STRING),
            new F('numbering_reset', ['settings', 'numbering', 'reset'], F::STRING),
            new F('design', ['settings', 'document', 'design'], F::STRING),
            new F('send_to_customer', ['settings', 'sendToCustomer'], F::BOOL),
        ];
    }

    /** @return list<F> */
    private function pageFields(): array
    {
        return [new F('key', ['key'], F::STRING), new F('title', ['title'], F::STRING),
            new F('description', ['description'], F::STRING), new F('visible_if', ['visibleIf'], F::LOGIC)];
    }

    /** Clearing the numbering format in the builder removes numbering altogether. */
    private function dropIncompleteNumbering(stdClass $definition, stdClass $source): void
    {
        $numbering = JsonPath::get($definition, ['settings', 'numbering']);
        if ($numbering instanceof stdClass && ! is_string($numbering->format ?? null)) {
            JsonPath::unset($definition, ['settings', 'numbering'], (object) []);
            $settings = $definition->settings ?? null;
            if ($settings instanceof stdClass && get_object_vars($settings) === [] && ! property_exists($source, 'settings')) {
                unset($definition->settings);
            }
        }
    }

    /** id, type and key first for readable JSON. */
    private function ordered(stdClass $element): stdClass
    {
        $ordered = new stdClass;
        foreach (['id', 'type', 'key'] as $first) {
            $ordered->{$first} = $element->{$first};
        }
        foreach (get_object_vars($element) as $name => $value) {
            if (! in_array($name, ['id', 'type', 'key'], true)) {
                $ordered->{$name} = $value;
            }
        }

        return $ordered;
    }

    private function without(stdClass $node, string ...$properties): stdClass
    {
        $copy = Json::decode(Json::encode($node));
        $copy = $copy instanceof stdClass ? $copy : new stdClass;
        foreach ($properties as $property) {
            unset($copy->{$property});
        }

        return $copy;
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
