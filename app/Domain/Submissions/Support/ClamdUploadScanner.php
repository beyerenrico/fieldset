<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

use Closure;

/**
 * Scans bytes with a clamd daemon over TCP using the INSTREAM command (ADR 0040): `zINSTREAM\0`, then
 * chunks prefixed with their 4-byte big-endian length, then a zero-length chunk. clamd answers
 * `stream: OK`, `stream: <signature> FOUND` or `<message> ERROR` (e.g. when StreamMaxLength is exceeded).
 * The host is operator configuration (a sidecar container), not user input, so no SSRF guard applies.
 */
final readonly class ClamdUploadScanner implements UploadScanner
{
    private const int MAX_REPLY_BYTES = 4096;

    /**
     * @param  (Closure(string, int, float): mixed)|null  $connect  opens the stream (tests pass a socket pair)
     */
    public function __construct(
        private string $host,
        private int $port,
        private float $timeoutSeconds = 10.0,
        private int $chunkBytes = 65_536,
        private ?Closure $connect = null,
    ) {}

    public function scan(string $bytes): UploadScanResult
    {
        $socket = $this->open();

        try {
            $this->write($socket, "zINSTREAM\0");
            for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += $this->chunkBytes) {
                $chunk = substr($bytes, $offset, $this->chunkBytes);
                $this->write($socket, pack('N', strlen($chunk)).$chunk);
            }
            $this->write($socket, pack('N', 0));

            return self::verdict($this->read($socket));
        } finally {
            fclose($socket);
        }
    }

    /** Parses a clamd INSTREAM reply. */
    public static function verdict(string $reply): UploadScanResult
    {
        $reply = trim($reply, "\0\r\n ");
        if ($reply === 'stream: OK') {
            return UploadScanResult::clean();
        }
        if (preg_match('/^stream: (.+) FOUND$/', $reply, $match) === 1) {
            return UploadScanResult::infected($match[1]);
        }

        throw new UploadScanFailed('clamd answered: '.mb_substr($reply, 0, 200), tooLarge: self::sizeLimit($reply));
    }

    private static function sizeLimit(string $reply): bool
    {
        return stripos($reply, 'size limit exceeded') !== false;
    }

    /** @return resource */
    private function open(): mixed
    {
        $socket = $this->connect instanceof Closure
            ? ($this->connect)($this->host, $this->port, $this->timeoutSeconds)
            : @stream_socket_client("tcp://{$this->host}:{$this->port}", $code, $message, $this->timeoutSeconds);

        if (! is_resource($socket)) {
            throw new UploadScanFailed("clamd is not reachable at {$this->host}:{$this->port}.");
        }
        stream_set_timeout($socket, (int) floor($this->timeoutSeconds), (int) (fmod($this->timeoutSeconds, 1) * 1_000_000));

        return $socket;
    }

    /** @param  resource  $socket */
    private function write(mixed $socket, string $data): void
    {
        for ($written = 0, $length = strlen($data); $written < $length; $written += $sent) {
            $sent = @fwrite($socket, substr($data, $written));
            if ($sent === false || $sent === 0) {
                // clamd answers "INSTREAM size limit exceeded. ERROR" and closes once StreamMaxLength is passed.
                $reply = $this->tryRead($socket);
                throw new UploadScanFailed('clamd closed the connection while receiving the stream: '.mb_substr(trim($reply, "\0\r\n "), 0, 200), tooLarge: self::sizeLimit($reply));
            }
        }
    }

    /** @param  resource  $socket */
    private function tryRead(mixed $socket): string
    {
        try {
            return $this->read($socket);
        } catch (UploadScanFailed) {
            return '';
        }
    }

    /** @param  resource  $socket */
    private function read(mixed $socket): string
    {
        $reply = '';
        while (! str_contains($reply, "\0") && strlen($reply) < self::MAX_REPLY_BYTES && ! feof($socket)) {
            $part = fread($socket, self::MAX_REPLY_BYTES);
            if ($part === false || ($part === '' && stream_get_meta_data($socket)['timed_out'])) {
                throw new UploadScanFailed('clamd did not answer in time.');
            }
            $reply .= $part;
        }

        return $reply;
    }
}
