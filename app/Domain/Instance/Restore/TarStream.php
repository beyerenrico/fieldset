<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use Closure;

/**
 * Reads the members of a tar archive from a stream, one 512-byte header at a time (plan U17, ADR 0085), so a restore can
 * judge every member of a foreign archive before anything is extracted. It understands what GNU tar writes (the image's
 * `tar`, the 1.0 `scripts/backup.sh`): ustar headers, GNU long names (`L`) and long link names (`K`), pax extended
 * headers (`x`, `g`) with `path`/`linkpath`/`size`, and base-256 sizes. Each member reaches `$each` with its data still
 * unread: the callback may read exactly `size` bytes through `$read`, or leave them, and the reader skips the rest.
 * A header with a wrong checksum, or a stream that ends inside a member, is an UnsafeArchive: never guess.
 */
final class TarStream
{
    private const int BLOCK = 512;

    /** Longest GNU long name or pax header this reader accepts (a member name is at most a few KiB). */
    private const int MAX_META = 65536;

    /** @param resource $stream */
    public function __construct(private $stream) {}

    /**
     * @param  Closure(TarMember, Closure(int): string): void  $each  the member and a reader for its data
     *
     * @throws UnsafeArchive
     */
    public function each(Closure $each): int
    {
        $count = 0;
        $pending = [];
        while (($header = $this->block()) !== null) {
            if (trim($header, "\0") === '') {
                $this->assertOnlyZeros(); // GNU tar may read on after a lone zero block: nothing may hide there

                return $count;
            }
            $member = TarMember::fromHeader($header, $pending);
            if (! $member->isMeta() && $member->type !== TarMember::REGULAR && $member->size !== 0) {
                // GNU tar skips no data after a directory (or link) header and would read those bytes as headers.
                throw new UnsafeArchive('The archive has a member of type \''.$member->type.'\' that carries data.');
            }
            if ($member->isMeta()) {
                $pending = $member->applyMeta($pending, $this->exactly($member->size, self::MAX_META));
                $this->skip($this->padding($member->size));

                continue;
            }
            $pending = [];
            $left = $member->size;
            $read = function (int $length) use (&$left): string {
                $chunk = $this->exactly(min($length, $left), PHP_INT_MAX);
                $left -= strlen($chunk);

                return $chunk;
            };
            $each($member, $read);
            $this->skip($left + $this->padding($member->size));
            $count++;
        }

        throw new UnsafeArchive('The archive ends without its end-of-archive blocks.');
    }

    /** The rest of the stream after the end-of-archive block must be zero bytes (padding to the record size). */
    private function assertOnlyZeros(): void
    {
        while (($chunk = $this->read(1 << 16)) !== '') {
            if (trim($chunk, "\0") !== '') {
                throw new UnsafeArchive('The archive has data after its end-of-archive block.');
            }
        }
    }

    private function padding(int $size): int
    {
        return (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
    }

    private function block(): ?string
    {
        $block = $this->read(self::BLOCK);
        if ($block === '') {
            return null;
        }
        if (strlen($block) !== self::BLOCK) {
            throw new UnsafeArchive('The archive ends inside a header.');
        }

        return $block;
    }

    /** Reads exactly `$length` bytes (refusing more than `$limit`); used for meta data and members' contents. */
    private function exactly(int $length, int $limit): string
    {
        if ($length > $limit) {
            throw new UnsafeArchive('An extended header or long name in the archive is too large.');
        }
        $data = '';
        while (strlen($data) < $length) {
            $chunk = $this->read(min(1 << 20, $length - strlen($data)));
            if ($chunk === '') {
                throw new UnsafeArchive('The archive ends inside a member.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function skip(int $length): void
    {
        while ($length > 0) {
            $chunk = $this->read(min(1 << 20, $length));
            if ($chunk === '') {
                throw new UnsafeArchive('The archive ends inside a member.');
            }
            $length -= strlen($chunk);
        }
    }

    private function read(int $length): string
    {
        $data = '';
        while (strlen($data) < $length && ! feof($this->stream)) {
            $chunk = fread($this->stream, max(1, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }
}
