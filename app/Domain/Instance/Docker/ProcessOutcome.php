<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/** Exit code and output of one CLI call. */
final readonly class ProcessOutcome
{
    public function __construct(
        public int $exitCode,
        public string $stdout = '',
        public string $stderr = '',
        public bool $timedOut = false,
        public bool $started = true,
    ) {}

    /** The executable could not be started at all (missing binary). */
    public static function notStarted(string $error): self
    {
        return new self(127, '', $error, false, false);
    }

    public function successful(): bool
    {
        return $this->started && $this->exitCode === 0 && ! $this->timedOut;
    }

    /** The last lines of stderr, for error messages and the "last log lines" of a failure. */
    public function stderrTail(int $lines = 20): string
    {
        $all = preg_split('/\R/', trim($this->stderr)) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }
}
