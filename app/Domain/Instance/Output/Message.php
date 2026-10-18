<?php

declare(strict_types=1);

namespace App\Domain\Instance\Output;

/** One entry of the envelope's `warnings` or `errors` list: a stable code, a sentence and an optional fix. */
final readonly class Message
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $hint = null,
        public ?bool $retryable = null,
    ) {}

    public static function warning(string $code, string $message, ?string $hint = null): self
    {
        return new self($code, $message, $hint);
    }

    public static function error(string $code, string $message, ?string $hint = null, bool $retryable = false): self
    {
        return new self($code, $message, $hint, $retryable);
    }

    /** @return array{code: string, message: string, hint: string|null, retryable?: bool} */
    public function toArray(): array
    {
        $entry = ['code' => $this->code, 'message' => $this->message, 'hint' => $this->hint];

        return $this->retryable === null ? $entry : [...$entry, 'retryable' => $this->retryable];
    }
}
