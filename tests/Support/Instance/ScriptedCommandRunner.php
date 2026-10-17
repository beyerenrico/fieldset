<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Docker\CommandRunner;
use App\Domain\Instance\Docker\ProcessOutcome;

/** CommandRunner double: records each argv with its environment, input and timeout, and replays queued outcomes. */
final class ScriptedCommandRunner implements CommandRunner
{
    /** @var list<array{command: list<string>, env: array<string, string|false>, input: string|null, timeout: float|null}> */
    public array $runs = [];

    /** @var list<ProcessOutcome> */
    private array $queue = [];

    public function push(ProcessOutcome ...$outcomes): self
    {
        array_push($this->queue, ...$outcomes);

        return $this;
    }

    public function run(array $command, array $env = [], ?string $input = null, ?float $timeout = null): ProcessOutcome
    {
        $this->runs[] = ['command' => $command, 'env' => $env, 'input' => $input, 'timeout' => $timeout];

        return array_shift($this->queue) ?? new ProcessOutcome(0);
    }

    /** @return list<string> */
    public function lastCommand(): array
    {
        return $this->runs[array_key_last($this->runs)]['command'];
    }
}
