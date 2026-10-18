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
 * indistinguishable from a one-off container that exits 127. With a stdout file, output streams through FileSink
 * and never accumulates in memory.
 */
final readonly class SymfonyCommandRunner implements CommandRunner
{
    public function run(array $command, array $env = [], ?string $input = null, ?float $timeout = null, ?string $stdoutFile = null): ProcessOutcome
    {
        $binary = $command[0] ?? '';
        $found = str_contains($binary, '/') ? is_file($binary) && is_executable($binary) : (new ExecutableFinder)->find($binary) !== null;
        if (! $found) {
            return ProcessOutcome::notStarted("Executable not found: {$binary}");
        }
        $sink = $stdoutFile === null ? null : FileSink::create($stdoutFile);
        if ($stdoutFile !== null && ! $sink instanceof FileSink) {
            return new ProcessOutcome(1, '', "The output file {$stdoutFile} cannot be created (it exists or its directory is not writable).");
        }
        $process = new Process($command, null, $env, $input, $timeout);

        try {
            $process->run($sink?->callback($process));
        } catch (ProcessTimedOutException) {
            $sink?->close();

            return new ProcessOutcome(124, $process->getOutput(), $process->getErrorOutput(), true);
        } catch (ProcessRuntimeException $e) {
            $sink?->close();

            return ProcessOutcome::notStarted($e->getMessage());
        }

        return $this->outcome($process, $sink, $stdoutFile);
    }

    private function outcome(Process $process, ?FileSink $sink, ?string $stdoutFile): ProcessOutcome
    {
        if (! $sink instanceof FileSink) {
            return new ProcessOutcome((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
        }
        $written = $sink->close();

        return $written
            ? new ProcessOutcome((int) $process->getExitCode(), '', $process->getErrorOutput())
            : new ProcessOutcome(1, '', trim($process->getErrorOutput()."\nWriting {$stdoutFile} failed (disk full?)."));
    }
}
