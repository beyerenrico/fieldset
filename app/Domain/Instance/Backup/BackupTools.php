<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use Closure;
use Symfony\Component\Process\Process;

/**
 * The programs a backup runs inside the backup service (ADR 0084), without a shell, connection values only in the
 * environment: PGDG's `pg_dump`/`pg_restore` 16 (E13, ADR 0059) over the stack network, and GNU tar. Each returns its
 * outcome; BackupWriter decides what counts as a failure.
 */
class BackupTools
{
    /** pg_dump and tar write files, not output, so no idle timeout fits; six hours is the hard stop for a hung run. */
    public const float DUMP_TIMEOUT = 21600.0;

    /** @param  array<string, string>  $libpq */
    public function dump(string $snapshot, string $file, array $libpq): BackupToolResult
    {
        return $this->run(['pg_dump', '--format=custom', '--no-owner', '--no-privileges', '--snapshot='.$snapshot, '--file='.$file], $libpq);
    }

    /** `pg_restore --list` reads the archive's table of contents: the check that the dump is a complete archive. */
    public function listDump(string $file): BackupToolResult
    {
        return $this->run(['pg_restore', '--list', $file]);
    }

    public function dumpVersion(): string
    {
        $result = $this->run(['pg_dump', '--version'], [], 30.0);

        return trim($result->stdout);
    }

    /** The storage volume as a gzip'd tar with numeric owners (restore extracts as the app UID, KTD18). */
    public function archive(string $source, string $file): BackupToolResult
    {
        return $this->run(['tar', '-C', $source, '--numeric-owner', '-czf', $file, '.']);
    }

    /**
     * Streams the archive's member names (without the leading `./`) to `$each`.
     *
     * @param  Closure(string): void  $each
     */
    public function members(string $file, Closure $each): BackupToolResult
    {
        $buffer = '';

        return $this->run(['tar', '-tzf', $file], [], self::DUMP_TIMEOUT, static function (string $type, string $chunk) use (&$buffer, $each): void {
            if ($type !== Process::OUT) {
                return;
            }
            $buffer .= $chunk;
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newline);
                $buffer = substr($buffer, $newline + 1);
                $each(str_starts_with($line, './') ? substr($line, 2) : $line);
            }
        });
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @param  (Closure(string, string): void)|null  $stream  receives output instead of the buffer
     */
    protected function run(array $command, array $env = [], float $timeout = self::DUMP_TIMEOUT, ?Closure $stream = null): BackupToolResult
    {
        $process = new Process($command, null, $env === [] ? null : $env);
        $process->setTimeout($timeout);
        $stderr = '';
        $process->run(static function (string $type, string $chunk) use ($stream, &$stderr, $process): void {
            if ($type === Process::ERR) {
                $stderr = substr($stderr.$chunk, -4096);
            }
            if ($stream instanceof Closure) {
                $stream($type, $chunk);
                $process->clearOutput(); // a streamed listing never piles up in memory (LEARNINGS 98)
            }
        });

        return new BackupToolResult((int) $process->getExitCode(), $stream instanceof Closure ? '' : $process->getOutput(), $stderr);
    }
}
