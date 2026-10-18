<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

use Closure;
use Symfony\Component\Process\Process;

/**
 * Streams a process's stdout into a new file instead of memory (database dumps, volume archives): the file is
 * created exclusively (never through an existing file or symlink) with mode 0600 before the first byte, each
 * chunk is written as it arrives and dropped from the process buffer, and closing flushes and fsyncs it.
 */
final class FileSink
{
    private bool $failed = false;

    /** @param  resource  $handle */
    private function __construct(private $handle) {}

    public static function create(string $path): ?self
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            return null;
        }
        if (! @chmod($path, 0600)) {
            fclose($handle);
            @unlink($path);

            return null;
        }

        return new self($handle);
    }

    /** @return Closure(string, string): void the Process::run() callback */
    public function callback(Process $process): Closure
    {
        return function (string $type, string $buffer) use ($process): void {
            if ($type !== Process::OUT) {
                return;
            }
            $this->failed = $this->failed || @fwrite($this->handle, $buffer) !== strlen($buffer);
            $process->clearOutput();
        };
    }

    /** @return bool whether every byte reached the disk */
    public function close(): bool
    {
        $synced = ! $this->failed && fflush($this->handle) && @fsync($this->handle);
        fclose($this->handle);

        return $synced;
    }
}
