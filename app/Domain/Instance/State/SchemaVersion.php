<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

use Stringable;

/**
 * `schema_version` of files that two versions of fsa read (state, journal, lock, marker; KTD6). A newer minor
 * only adds keys, which readers keep; a higher major is incompatible and makes commands exit 7 rather than
 * rewrite the file.
 */
final readonly class SchemaVersion implements Stringable
{
    public function __construct(
        public int $major,
        public int $minor = 0,
    ) {}

    /** @throws InvalidState when the value is missing or malformed */
    public static function parse(mixed $raw, string $document): self
    {
        if (is_int($raw) && $raw >= 0) {
            return new self($raw);
        }
        if (is_string($raw) && preg_match('/^(\d{1,4})\.(\d{1,4})$/', $raw, $m) === 1) {
            return new self((int) $m[1], (int) $m[2]);
        }

        throw new InvalidState("The {$document} has no valid schema_version.");
    }

    public static function of(string $version): self
    {
        return self::parse($version, 'built-in schema');
    }

    /** @throws IncompatibleSchema when the file was written by a newer major version */
    public function assertReadableBy(self $supported, string $document): void
    {
        if ($this->major > $supported->major) {
            throw new IncompatibleSchema($document, (string) $this, (string) $supported);
        }
    }

    /** The version to write back: the newer minor survives, so its readers still find their keys. */
    public function max(self $other): self
    {
        return $this->major === $other->major && $other->minor > $this->minor ? $other : $this;
    }

    public function __toString(): string
    {
        return "{$this->major}.{$this->minor}";
    }
}
