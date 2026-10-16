<?php

declare(strict_types=1);

namespace FormSchema\Validation;

final readonly class MetaSchemaResult
{
    /**
     * @param  list<array{path: string, message: string}>  $errors  path is a JSON pointer into the definition
     */
    public function __construct(
        public bool $valid,
        public array $errors = [],
    ) {}
}
