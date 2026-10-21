<?php

declare(strict_types=1);

namespace Fieldset\Evaluation;

use JsonSerializable;

/**
 * A validation error (spec §5.6): JSON pointer into the submission data, stable code, params.
 */
final readonly class ValidationError implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public string $pointer,
        public string $code,
        public array $params = [],
    ) {}

    /**
     * @return array{pointer: string, code: string, params?: array<string, mixed>}
     */
    public function toArray(): array
    {
        $error = ['pointer' => $this->pointer, 'code' => $this->code];

        if ($this->params !== []) {
            $error['params'] = $this->params;
        }

        return $error;
    }

    /**
     * @return array{pointer: string, code: string, params?: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
