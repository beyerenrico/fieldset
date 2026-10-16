<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Engine;

/** Outcome of a step: done (continue with the next step) or waiting (the run pauses until resumed). */
final readonly class StepResult
{
    /** @param  array<string, mixed>  $output */
    private function __construct(public bool $waiting, public string $message, public array $output) {}

    /** @param  array<string, mixed>  $output */
    public static function completed(string $message, array $output = []): self
    {
        return new self(false, $message, $output);
    }

    /** @param  array<string, mixed>  $output */
    public static function waiting(string $message, array $output = []): self
    {
        return new self(true, $message, $output);
    }
}
