<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

/**
 * One tar header as TarStream reads it (plan U17, ADR 0085): the member's name (after GNU long names and pax `path`),
 * its type, size, mode and link target. GNU tar's own meta members (`L` long name, `K` long link name, `x`/`g` pax
 * headers) are recognised so their values apply to the next member; they never reach a caller. The checksum is
 * verified, and sizes are read as octal or base-256.
 */
final readonly class TarMember
{
    public const string REGULAR = '0';

    public const string DIRECTORY = '5';

    private const array META = ['L', 'K', 'x', 'g'];

    private function __construct(
        public string $name,
        public string $type,
        public int $size,
        public int $mode,
        public string $link,
    ) {}

    /**
     * @param  array{path?: string, linkpath?: string, size?: int}  $pending  values meta members set for this one
     *
     * @throws UnsafeArchive
     */
    public static function fromHeader(string $header, array $pending): self
    {
        self::assertChecksum($header);
        $type = $header[156] === "\0" ? self::REGULAR : $header[156];
        $magic = substr($header, 257, 6);
        $name = self::field($header, 0, 100);
        if ($magic === "ustar\0" && ($prefix = self::field($header, 345, 155)) !== '') {
            $name = $prefix.'/'.$name; // POSIX ustar; GNU's `ustar  ` magic uses that area for times
        }
        $size = self::number(substr($header, 124, 12));

        return new self(
            $pending['path'] ?? $name,
            $type,
            in_array($type, self::META, true) ? $size : ($pending['size'] ?? $size),
            self::number(substr($header, 100, 8)),
            $pending['linkpath'] ?? self::field($header, 157, 100),
        );
    }

    public function isMeta(): bool
    {
        return in_array($this->type, self::META, true);
    }

    /**
     * The values this meta member sets for the next member.
     *
     * @param  array{path?: string, linkpath?: string, size?: int}  $pending
     * @return array{path?: string, linkpath?: string, size?: int}
     *
     * @throws UnsafeArchive
     */
    public function applyMeta(array $pending, string $data): array
    {
        return match ($this->type) {
            'L' => [...$pending, 'path' => rtrim($data, "\0")],
            'K' => [...$pending, 'linkpath' => rtrim($data, "\0")],
            'g' => self::globalPax($pending, $data),
            default => [...$pending, ...self::pax($data)],
        };
    }

    /**
     * Pax records (`<length> <key>=<value>\n`); only the keys that change what a member is are kept.
     *
     * @return array{path?: string, linkpath?: string, size?: int}
     *
     * @throws UnsafeArchive
     */
    private static function pax(string $data): array
    {
        $values = [];
        $offset = 0;
        while ($offset < strlen($data)) {
            if (preg_match('/\G(\d{1,10}) /', $data, $match, 0, $offset) !== 1 || (int) $match[1] < strlen($match[0]) + 2) {
                throw new UnsafeArchive('The archive has a malformed pax header.');
            }
            $record = substr($data, $offset + strlen($match[0]), (int) $match[1] - strlen($match[0]));
            $offset += (int) $match[1];
            [$key, $value] = array_pad(explode('=', rtrim($record, "\n"), 2), 2, null);
            if ($value === null) {
                throw new UnsafeArchive('The archive has a malformed pax record.');
            }
            match ($key) {
                'path', 'linkpath' => $values[$key] = $value,
                'size' => $values['size'] = ctype_digit($value) ? (int) $value : throw new UnsafeArchive('The archive has a malformed pax size.'),
                default => null,
            };
        }

        return $values;
    }

    /**
     * A global pax header may not rename members (GNU tar never writes one that does).
     *
     * @param  array{path?: string, linkpath?: string, size?: int}  $pending
     * @return array{path?: string, linkpath?: string, size?: int}
     */
    private static function globalPax(array $pending, string $data): array
    {
        if (self::pax($data) !== []) {
            throw new UnsafeArchive('The archive has a global pax header that changes member names or sizes.');
        }

        return $pending;
    }

    private static function field(string $header, int $offset, int $length): string
    {
        $value = substr($header, $offset, $length);
        $end = strpos($value, "\0");

        return $end === false ? $value : substr($value, 0, $end);
    }

    /** @throws UnsafeArchive */
    private static function number(string $field): int
    {
        if ($field !== '' && (ord($field[0]) & 0x80) !== 0) { // GNU base-256: big-endian, high bit set
            $value = ord($field[0]) & 0x7F;
            for ($i = 1, $n = strlen($field); $i < $n; $i++) {
                if ($value > (PHP_INT_MAX >> 8)) {
                    throw new UnsafeArchive('The archive has a number too large to read.');
                }
                $value = ($value << 8) | ord($field[$i]);
            }

            return $value;
        }
        $digits = trim($field, " \0");
        if ($digits !== '' && preg_match('/^[0-7]{1,22}$/D', $digits) !== 1) {
            throw new UnsafeArchive('The archive has a malformed number in a header.');
        }

        return $digits === '' ? 0 : (int) octdec($digits);
    }

    /** @throws UnsafeArchive */
    private static function assertChecksum(string $header): void
    {
        $stored = trim(substr($header, 148, 8), " \0");
        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ($i >= 148 && $i < 156) ? 32 : ord($header[$i]);
        }
        if ($stored === '' || preg_match('/^[0-7]+$/D', $stored) !== 1 || (int) octdec($stored) !== $sum) {
            throw new UnsafeArchive('The archive has a header with a wrong checksum.');
        }
    }
}
