<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

/** The outcome of a program BackupTools ran: exit code, stdout (unless streamed) and the tail of stderr. */
final readonly class BackupToolResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout = '',
        public string $stderr = '',
    ) {}

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
