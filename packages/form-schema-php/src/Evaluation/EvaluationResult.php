<?php

declare(strict_types=1);

namespace FormSchema\Evaluation;

use stdClass;

final readonly class EvaluationResult
{
    /**
     * @param  stdClass  $data  normalized, stripped submission data (JSON tree)
     * @param  list<ValidationError>  $errors  in document order, rows in index order
     * @param  array<string, bool>  $visibility  scope pointer of every element => visible
     */
    public function __construct(
        public stdClass $data,
        public array $errors,
        public array $visibility,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return list<array{pointer: string, code: string, params?: array<string, mixed>}>
     */
    public function errorsToArray(): array
    {
        return array_map(static fn (ValidationError $error): array => $error->toArray(), $this->errors);
    }
}
