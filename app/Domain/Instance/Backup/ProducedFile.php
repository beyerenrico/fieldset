<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use RuntimeException;

/**
 * A file of a backup set as the writer produced it (M12 gate S1, ADR 0084): its SHA-256 and size, read through the
 * descriptor the writer created the file with (never by path again), and the device and inode it lives on, so a later
 * check can tell that the path still names that very file. `SHA256SUMS` lists these hashes, not what a path holds at the
 * end of the run.
 */
final readonly class ProducedFile
{
    public function __construct(
        public string $sha256,
        public int $bytes,
        public ?int $device = null,
        public ?int $inode = null,
    ) {}

    /**
     * Creates `$path` exclusively, 0600 from the first moment (umask 0077: no chmod by path afterwards), never through a
     * symlink.
     *
     * @return resource
     */
    public static function create(string $path): mixed
    {
        $umask = umask(0077);

        try {
            $handle = @fopen($path, 'x+');
        } finally {
            umask($umask);
        }
        if ($handle === false) {
            throw new RuntimeException('Cannot create '.basename($path).'.');
        }

        return $handle;
    }

    /**
     * What a program wrote into `$handle`: synced to the disk, then hashed from the start through the same descriptor.
     *
     * @param  resource  $handle
     */
    public static function of(mixed $handle): self
    {
        $stat = fflush($handle) && fsync($handle) ? fstat($handle) : false;
        if ($stat === false || ! rewind($handle)) {
            throw new RuntimeException('Cannot sync a file of the backup set.');
        }
        $context = hash_init('sha256');
        hash_update_stream($context, $handle);

        return new self(hash_final($context), (int) $stat['size'], (int) $stat['dev'], (int) $stat['ino']);
    }

    /** A file another program wrote by its path (age's output): synced, then hashed. */
    public static function read(string $path): self
    {
        $handle = is_link($path) ? false : @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Cannot read '.basename($path).'.');
        }

        try {
            return self::of($handle);
        } finally {
            fclose($handle);
        }
    }

    /** A file the writer wrote from a string it holds. */
    public static function written(string $path, string $content): self
    {
        $handle = self::create($path);

        try {
            if (fwrite($handle, $content) !== strlen($content)) {
                throw new RuntimeException('Cannot write '.basename($path).'.');
            }
            $stat = fstat($handle);
            if (! fflush($handle) || ! fsync($handle) || $stat === false) {
                throw new RuntimeException('Cannot sync '.basename($path).'.');
            }
        } finally {
            fclose($handle);
        }

        return new self(hash('sha256', $content), strlen($content), (int) $stat['dev'], (int) $stat['ino']);
    }

    /** @return array{bytes: int, sha256: string} */
    public function describe(): array
    {
        return ['bytes' => $this->bytes, 'sha256' => $this->sha256];
    }
}
