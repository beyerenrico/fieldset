<?php

declare(strict_types=1);

namespace FormSchema\Definition;

/**
 * A data scope: the submission root or one repeater row (spec §1).
 */
final readonly class Scope
{
    /**
     * @param  string  $id  '' for the root, otherwise the path of the repeater element
     * @param  string|null  $parentId  id of the enclosing scope; null for the root
     * @param  array<string, Element>  $elements  data elements of this scope by key (first definition wins)
     */
    public function __construct(
        public string $id,
        public ?Element $repeater,
        public ?string $parentId,
        public array $elements,
    ) {}

    public function isRoot(): bool
    {
        return $this->repeater === null;
    }

    public function element(string $key): ?Element
    {
        return $this->elements[$key] ?? null;
    }
}
