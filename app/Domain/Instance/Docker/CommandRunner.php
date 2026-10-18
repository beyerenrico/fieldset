<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

use Illuminate\Container\Attributes\Bind;

/** Runs one external command; the seam that lets CliDockerGateway be tested by its exact argv. */
#[Bind(SymfonyCommandRunner::class)]
interface CommandRunner
{
    /**
     * @param  list<string>  $command  argv; never contains secret values
     * @param  array<string, string|false>  $env  added to the inherited environment; false unsets a variable
     * @param  string|null  $stdoutFile  stream stdout into this new file (0600) instead of memory; stdout is then ''
     */
    public function run(array $command, array $env = [], ?string $input = null, ?float $timeout = null, ?string $stdoutFile = null): ProcessOutcome;
}
