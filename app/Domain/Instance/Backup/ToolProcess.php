<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use Closure;

/**
 * Runs one backup program without a shell (BackupTools, ADR 0084). Unlike Symfony's Process it can hand the program a
 * file the writer opened itself as its stdout (M12 gate S1): `pg_dump` and `tar` write into a file descriptor the writer
 * holds, so the bytes it hashes afterwards are the bytes the program produced, whatever happens to the path meanwhile.
 * stdin is `/dev/null`; stderr keeps its last 4 KiB; stdout (when not a file) is buffered or streamed to `$stream` in
 * chunks (`out`); one deadline for the whole run, after which the program is killed (exit 124).
 */
final class ToolProcess
{
    public const int TIMED_OUT = 124;

    private const int CHUNK = 65536;

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env  merged over the caller's environment (like Symfony's Process)
     * @param  (Closure(string, string): void)|null  $stream  receives stdout chunks instead of the buffer
     * @param  resource|null  $stdout  a file the program writes to instead of a pipe
     */
    public static function run(array $command, array $env, float $timeout, ?Closure $stream = null, mixed $stdout = null): BackupToolResult
    {
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => is_resource($stdout) ? $stdout : ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes, null, $env === [] ? null : [...getenv(), ...$env]);
        if (! is_resource($process)) {
            return new BackupToolResult(127, '', "{$command[0]} could not start.");
        }
        $open = array_filter([1 => $pipes[1] ?? null, 2 => $pipes[2] ?? null], is_resource(...));
        foreach ($open as $pipe) {
            stream_set_blocking($pipe, false);
        }
        [$output, $stderr, $timedOut] = self::drain($process, $open, microtime(true) + $timeout, $stream);
        $code = proc_close($process);

        return $timedOut
            ? new BackupToolResult(self::TIMED_OUT, '', trim($stderr."\n".$command[0].' timed out after '.(int) $timeout.' s.'))
            : new BackupToolResult($code, $output, $stderr);
    }

    /**
     * Reads stdout and stderr until both close or the deadline passes (then the program is killed).
     *
     * @param  resource  $process
     * @param  array<int, resource>  $open
     * @param  (Closure(string, string): void)|null  $stream
     * @return array{0: string, 1: string, 2: bool} stdout, the tail of stderr, whether it timed out
     */
    private static function drain(mixed $process, array $open, float $deadline, ?Closure $stream): array
    {
        $output = '';
        $stderr = '';
        while ($open !== []) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                proc_terminate($process, 9);
                array_map(fclose(...), $open);

                return [$output, $stderr, true];
            }
            $read = array_values($open);
            $write = $except = null;
            if (@stream_select($read, $write, $except, (int) min(1, $left), $left < 1 ? (int) ($left * 1e6) : 0) === false) {
                usleep(10000); // a signal interrupted the wait (the daemon handles SIGTERM): wait again

                continue;
            }
            foreach ($read as $pipe) {
                $fd = (int) array_search($pipe, $open, true);
                $chunk = fread($pipe, self::CHUNK);
                if ($chunk === false || $chunk === '') {
                    if (feof($pipe)) {
                        fclose($pipe);
                        unset($open[$fd]);
                    }

                    continue;
                }
                match (true) {
                    $fd === 2 => $stderr = substr($stderr.$chunk, -4096),
                    $stream instanceof Closure => $stream('out', $chunk),
                    default => $output .= $chunk,
                };
            }
        }

        return [$output, $stderr, false];
    }
}
