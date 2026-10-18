<?php

declare(strict_types=1);

namespace App\Domain\Instance\Output;

use SensitiveParameter;

/**
 * Registry of the secret values one `fsa` command has seen. Everything rendered outside `secrets` (data,
 * warnings, errors, NDJSON progress, human output) passes through it, so a secret that leaks into a message by
 * mistake is replaced instead of printed (R50). Deliberately mutable: secrets become known while a command runs.
 */
final class SecretRedactor
{
    public const string REPLACEMENT = '[redacted]';

    /** Shorter values would redact ordinary words; generated secrets are far longer. */
    private const int MIN_LENGTH = 4;

    /** @var array<string, true> */
    private array $values = [];

    public function register(#[SensitiveParameter] string $value): void
    {
        if (strlen($value) >= self::MIN_LENGTH) {
            $this->values[$value] = true;
        }
    }

    public function redact(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactString($value);
        }
        if (is_array($value)) {
            return array_map($this->redact(...), $value);
        }

        return $value;
    }

    public function redactString(string $text): string
    {
        if ($this->values === []) {
            return $text;
        }
        // Longest first, so a secret that contains another one is replaced as a whole.
        $values = array_map(strval(...), array_keys($this->values));
        usort($values, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return str_replace($values, self::REPLACEMENT, $text);
    }
}
