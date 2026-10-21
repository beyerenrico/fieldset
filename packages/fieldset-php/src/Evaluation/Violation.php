<?php

declare(strict_types=1);

namespace Fieldset\Evaluation;

/**
 * A failed check of one value, before it is bound to a pointer.
 */
final readonly class Violation
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public string $code,
        public array $params = [],
    ) {}

    public function at(string $pointer): ValidationError
    {
        return new ValidationError($pointer, $this->code, $this->params);
    }
}
