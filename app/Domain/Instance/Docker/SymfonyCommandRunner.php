<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Symfony Process implementation (process precedent: QpdfAcroFormReader). No shell is involved. A missing
 * executable is reported as "not started": depending on the platform proc_open would otherwise fork and exit 127,
 * indistinguishable from a one-off container that exits 127.
 */
final readonly class SymfonyCommandRunner implements CommandRunner
{
    public function run(array $command, array $env = [], ?string $input = null, ?float $timeout = null): ProcessOutcome
    {
        $binary = $command[0] ?? '';
        $found = str_contains($binary, '/') ? is_file($binary) && is_executable($binary) : (new ExecutableFinder)->find($binary) !== null;
        if (! $found) {
            return ProcessOutcome::notStarted("Executable not found: {$binary}");
        }
        $process = new Process($command, null, $env, $input, $timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new ProcessOutcome(124, $process->getOutput(), $process->getErrorOutput(), true);
        } catch (ProcessRuntimeException $e) {
            return ProcessOutcome::notStarted($e->getMessage());
        }

        return new ProcessOutcome((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
    }
}
