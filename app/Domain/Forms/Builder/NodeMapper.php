<?php

declare(strict_types=1);

namespace App\Domain\Forms\Builder;

use App\Domain\Forms\Builder\FieldSpec as F;
use App\Domain\Forms\Support\BlankDefinition;
use Fieldset\Json\Json;
use InvalidArgumentException;
use stdClass;

/**
 * One node of a Form Definition (the settings, a page or an element) ↔ builder field state, without
 * its children (ADR 0022, ADR 0037). The state carries `_source`, the node's original JSON: values the
 * user did not change are written back verbatim, edited values go through FieldCodec, and unknown or
 * `x-*` properties survive untouched. Logic compiles with the key types of the whole definition.
 * Builder v1 (BuilderMapper) walks the tree with it; the Builder v2 inspector maps single nodes.
 */
final readonly class NodeMapper
{
    public const string SETTINGS = 'settings';

    public const string PAGE = 'page';

    public const string ELEMENT = 'element';

    public const array KINDS = [self::SETTINGS, self::PAGE, self::ELEMENT];

    private const array CHILDREN = [self::SETTINGS => ['pages'], self::PAGE => ['elements'], self::ELEMENT => ['elements', 'columns']];

    private FieldCodec $codec;

    /**
     * @param  array<string, string>  $keyTypes  key => element type of the whole definition
     * @param  list<string>  $lockedIds  element ids that appear in a published version (keys locked)
     */
    public function __construct(array $keyTypes = [], private array $lockedIds = [])
    {
        $this->codec = new FieldCodec(new LogicCompiler($keyTypes), new CalculationCompiler);
    }

    /** A copy of the node without its children (pages, elements, columns). */
    public static function withoutChildren(string $kind, stdClass $node): stdClass
    {
        $copy = Json::decode(Json::encode($node));
        $copy = $copy instanceof stdClass ? $copy : new stdClass;
        foreach (self::CHILDREN[self::assertKind($kind)] as $property) {
            unset($copy->{$property});
        }

        return $copy;
    }

    /** @return array<string, mixed> */
    public function toState(string $kind, stdClass $node): array
    {
        return match (self::assertKind($kind)) {
            self::SETTINGS => $this->settingsToState($node),
            self::PAGE => $this->pageToState($node),
            default => $this->elementToState($node),
        };
    }

    /**
     * @param  array<array-key, mixed>  $state
     * @param  string|null  $type  element type (defaults to the type in `_source`)
     */
    public function fromState(string $kind, array $state, ?string $type = null): stdClass
    {
        return match (self::assertKind($kind)) {
            self::SETTINGS => $this->settingsFromState($state),
            self::PAGE => $this->pageFromState($state),
            default => $this->elementFromState($type ?? $this->str(FieldCodec::decodeObject($state['_source'] ?? null)->type ?? null), $state),
        };
    }

    /** @return array<string, mixed> */
    public function settingsToState(stdClass $definition): array
    {
        return ['_source' => Json::encode(self::withoutChildren(self::SETTINGS, $definition))]
            + $this->read($definition, $this->definitionFields());
    }

    /** @param  array<array-key, mixed>  $state */
    public function settingsFromState(array $state): stdClass
    {
        $source = FieldCodec::decodeObject($state['_source'] ?? null);
        $definition = $this->apply($source, $state, $this->definitionFields());
        $this->dropIncompleteNumbering($definition, $source);

        return $definition;
    }

    /** @return array<string, mixed> */
    public function pageToState(stdClass $page): array
    {
        return ['_source' => Json::encode(self::withoutChildren(self::PAGE, $page)), 'id' => $this->str($page->id ?? null)]
            + $this->read($page, $this->pageFields());
    }

    /** @param  array<array-key, mixed>  $state */
    public function pageFromState(array $state): stdClass
    {
        $page = $this->apply(FieldCodec::decodeObject($state['_source'] ?? null), $state, $this->pageFields());
        $id = $this->str($state['id'] ?? null);
        if ($id !== '' || property_exists($page, 'id')) {
            $page->id = $id !== '' ? $id : BlankDefinition::id();
        }

        return $page;
    }

    /** @return array<string, mixed> */
    public function elementToState(stdClass $element): array
    {
        $type = $this->str($element->type ?? null);

        return ['_source' => Json::encode(self::withoutChildren(self::ELEMENT, $element)), 'id' => $this->str($element->id ?? null),
            'key' => $this->str($element->key ?? null), 'key_locked' => in_array($element->id ?? null, $this->lockedIds, true)]
            + $this->read($element, ElementFields::for($type));
    }

    /**
     * @param  array<array-key, mixed>  $state
     * @param  bool  $clone  the state is a copy of another element: fresh id and a `_copy` key
     */
    public function elementFromState(string $type, array $state, bool $clone = false): stdClass
    {
        $source = FieldCodec::decodeObject($state['_source'] ?? null);
        $element = $this->apply($source, $state, ElementFields::for($type));
        $element->type = $type;

        $id = $this->str($state['id'] ?? null);
        $key = $this->str($state['key'] ?? null);
        $originalKey = $this->str($source->key ?? null);
        if ($id !== '' && in_array($id, $this->lockedIds, true) && $originalKey !== '') {
            $key = $originalKey;
        }
        if ($id === '' || $clone) {
            $key = $clone && $key !== '' ? mb_substr($key, 0, 59).'_copy' : $key;
            $id = BlankDefinition::id();
        }
        $element->id = $id;
        $element->key = $key !== '' ? $key : 'field_'.substr(str_replace('-', '', $id), -8);

        return $this->ordered($element);
    }

    /**
     * @param  list<F>  $fields
     * @return array<string, mixed>
     */
    private function read(stdClass $source, array $fields): array
    {
        $data = [];
        foreach ($fields as $spec) {
            $data[$spec->ui] = $this->codec->toUi($spec, JsonPath::get($source, $spec->path));
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<F>  $fields
     */
    private function apply(stdClass $source, array $data, array $fields): stdClass
    {
        $target = Json::decode(Json::encode($source));
        $target = $target instanceof stdClass ? $target : new stdClass;

        foreach ($fields as $spec) {
            if (! array_key_exists($spec->ui, $data)) {
                continue;
            }
            $original = JsonPath::get($source, $spec->path);
            $unchanged = FieldCodec::normalize($data[$spec->ui]) === FieldCodec::normalize($this->codec->toUi($spec, $original));
            $value = $unchanged ? $original : $this->codec->fromUi($spec, $data[$spec->ui]);

            $value === JsonPath::ABSENT
                ? JsonPath::unset($target, $spec->path, $source)
                : JsonPath::set($target, $spec->path, $value);
        }

        return $target;
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

    private static function assertKind(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown builder node kind [{$kind}].");
        }

        return $kind;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
