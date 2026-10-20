<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

/**
 * The outcome of a program BackupTools ran: exit code, stdout (unless streamed or written to a file) and the tail of
 * stderr; for a program that wrote a file of the set, that file as the writer read it back through its own descriptor.
 */
final readonly class BackupToolResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout = '',
        public string $stderr = '',
        public ?ProducedFile $produced = null,
    ) {}

    public function withProduced(ProducedFile $produced): self
    {
        return new self($this->exitCode, $this->stdout, $this->stderr, $produced);
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /** The last lines of stderr, for an error message. */
    public function errorTail(int $lines = 3): string
    {
        return implode(' ', array_slice(array_values(array_filter(explode("\n", trim($this->stderr)))), -$lines));
    }
}
