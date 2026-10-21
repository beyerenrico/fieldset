<?php

declare(strict_types=1);

namespace Fieldset\Evaluation;

final readonly class NormalizedData
{
    /**
     * @param  array<string, mixed>  $values  root scope values (rows as arrays)
     * @param  list<ValidationError>  $errors  normalization errors (`type_mismatch` of repeaters and rows)
     */
    public function __construct(
        public array $values,
        public array $errors,
    ) {}
}
