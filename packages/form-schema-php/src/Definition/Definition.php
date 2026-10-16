<?php

declare(strict_types=1);

namespace FormSchema\Definition;

use FormSchema\Json\Json;
use stdClass;

/**
 * Immutable view of a Form Definition (spec §2–§3).
 *
 * The raw JSON object is a private deep copy of the input, so output stays lossless and
 * callers cannot change the definition after construction.
 */
final readonly class Definition
{
    /**
     * @param  list<Page>  $pages
     * @param  list<Element>  $elements  all elements in pre-order (pages in order)
     * @param  array<string, Scope>  $scopes  keyed by scope id ('' = root)
     */
    private function __construct(
        public stdClass $raw,
        public array $pages,
        public array $elements,
        public array $scopes,
    ) {}

    /**
     * @throws InvalidDefinitionException when the input is not a JSON object
     */
    public static function fromJson(mixed $json): self
    {
        if ($json instanceof self) {
            return $json;
        }

        if (! $json instanceof stdClass) {
            throw new InvalidDefinitionException('A form definition must be a JSON object.');
        }

        $raw = Json::copy($json);
        assert($raw instanceof stdClass);
        $pages = DefinitionParser::pages($raw);
        $elements = DefinitionParser::flatten($pages);

        return new self($raw, $pages, $elements, DefinitionParser::scopes($elements));
    }

    public function specVersion(): string
    {
        return $this->string('specVersion');
    }

    public function key(): string
    {
        return $this->string('key');
    }

    public function title(): string
    {
        return $this->string('title');
    }

    public function locale(): string
    {
        return $this->string('locale');
    }

    public function settings(): stdClass
    {
        $settings = $this->raw->settings ?? null;

        return $settings instanceof stdClass ? $settings : new stdClass;
    }

    /**
     * A deep copy of the definition JSON.
     */
    public function toJson(): stdClass
    {
        $copy = Json::copy($this->raw);
        assert($copy instanceof stdClass);

        return $copy;
    }

    /**
     * The page with the given JSON index (`/pages/<index>`).
     */
    public function page(int $index): ?Page
    {
        foreach ($this->pages as $page) {
            if ($page->index === $index) {
                return $page;
            }
        }

        return null;
    }

    public function rootScope(): Scope
    {
        return $this->scopes[''];
    }

    public function scope(string $id): ?Scope
    {
        return $this->scopes[$id] ?? null;
    }

    /**
     * Scopes from the given one outwards to the root.
     *
     * @return list<Scope>
     */
    public function scopeChain(string $scopeId): array
    {
        $chain = [];
        $scope = $this->scope($scopeId);

        while ($scope !== null) {
            $chain[] = $scope;
            $scope = $scope->parentId === null ? null : $this->scope($scope->parentId);
        }

        return $chain;
    }

    /**
     * Resolves a data key by definition: innermost scope first (spec §4.3).
     */
    public function resolveKey(string $key, string $scopeId): ?Element
    {
        foreach ($this->scopeChain($scopeId) as $scope) {
            $element = $scope->element($key);

            if ($element !== null) {
                return $element;
            }
        }

        return null;
    }

    /**
     * Enclosing repeaters of an element, outermost first.
     *
     * @return list<Element>
     */
    public function repeaterChain(Element $element): array
    {
        $repeaters = [];

        foreach ($this->scopeChain($element->scopeId) as $scope) {
            if ($scope->repeater !== null) {
                array_unshift($repeaters, $scope->repeater);
            }
        }

        return $repeaters;
    }

    public function elementByPath(string $path): ?Element
    {
        foreach ($this->elements as $element) {
            if ($element->path === $path) {
                return $element;
            }
        }

        return null;
    }

    public function elementById(string $id): ?Element
    {
        foreach ($this->elements as $element) {
            if ($element->id !== '' && strcasecmp($element->id, $id) === 0) {
                return $element;
            }
        }

        return null;
    }

    /**
     * @return list<Element>
     */
    public function calculatedElements(): array
    {
        return array_values(array_filter($this->elements, static fn (Element $e): bool => $e->isCalculated()));
    }

    /**
     * All elements nested below the given one, in pre-order.
     *
     * @return list<Element>
     */
    public function descendants(Element $element): array
    {
        $prefix = $element->path.'/';

        return array_values(array_filter(
            $this->elements,
            static fn (Element $e): bool => str_starts_with($e->path, $prefix),
        ));
    }

    /**
     * Document position (pre-order index) of an element path, for stable ordering.
     */
    public function position(string $path): int
    {
        foreach ($this->elements as $index => $element) {
            if ($element->path === $path) {
                return $index;
            }
        }

        return PHP_INT_MAX;
    }

    private function string(string $property): string
    {
        $value = $this->raw->{$property} ?? null;

        return is_string($value) ? $value : '';
    }
}
