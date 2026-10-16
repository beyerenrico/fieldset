<?php

declare(strict_types=1);

namespace FormSchema\Definition;

use stdClass;

final readonly class Page
{
    /**
     * @param  list<Element>  $elements
     */
    public function __construct(
        public stdClass $raw,
        public int $index,
        public string $key,
        public string $title,
        public string $path,
        public array $elements,
    ) {}

    public function has(string $property): bool
    {
        return property_exists($this->raw, $property);
    }

    public function get(string $property, mixed $default = null): mixed
    {
        return $this->has($property) ? $this->raw->{$property} : $default;
    }
}
