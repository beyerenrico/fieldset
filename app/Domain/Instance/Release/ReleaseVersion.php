<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

use Stringable;

/**
 * A release version: `X.Y.Z`, or a pre-release `X.Y.Z-alpha|beta|rc[.identifier…]` (semver 2.0 without build
 * metadata, because Docker tags cannot hold `+`). Pre-releases publish only their own image tag and never move
 * `X.Y`, `X` or `latest` (BUILD_PROMPT_V2 §6.5, KTD22).
 */
final readonly class ReleaseVersion implements Stringable
{
    /** Kept identical to the `version` pattern in distribution/manifest.schema.json (a unit test checks it). */
    public const string PATTERN = '^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)'
        .'(?:-((?:alpha|beta|rc)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?$';

    private function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        public ?string $prerelease,
    ) {}

    public static function parse(string $version): self
    {
        if (preg_match('/'.self::PATTERN.'/', $version, $match) !== 1) {
            throw new ReleaseException(sprintf(
                'Invalid release version "%s": expected X.Y.Z or X.Y.Z-alpha|beta|rc.N.',
                $version,
            ));
        }

        $prerelease = $match[4] ?? '';

        return new self((int) $match[1], (int) $match[2], (int) $match[3], $prerelease === '' ? null : $prerelease);
    }

    /** Parses a git tag (`v2.0.0-rc.1`); the leading `v` is required. */
    public static function fromTag(string $tag): self
    {
        if (! str_starts_with($tag, 'v')) {
            throw new ReleaseException(sprintf('Invalid release tag "%s": release tags start with "v".', $tag));
        }

        return self::parse(substr($tag, 1));
    }

    /** Accepts a version or a tag. */
    public static function fromInput(string $input): self
    {
        return str_starts_with($input, 'v') ? self::fromTag($input) : self::parse($input);
    }

    public function channel(): ReleaseChannel
    {
        if ($this->prerelease === null) {
            return ReleaseChannel::Stable;
        }

        return ReleaseChannel::from(explode('.', $this->prerelease, 2)[0]);
    }

    public function isPrerelease(): bool
    {
        return $this->prerelease !== null;
    }

    public function tag(): string
    {
        return 'v'.$this;
    }

    /**
     * Image tags this version publishes: a stable release moves `X.Y`, `X` and `latest`; a pre-release
     * publishes only its own version.
     *
     * @return list<string>
     */
    public function imageTags(): array
    {
        if ($this->isPrerelease()) {
            return [(string) $this];
        }

        return [(string) $this, "{$this->major}.{$this->minor}", (string) $this->major, 'latest'];
    }

    /** Semver precedence: negative when this version is lower than the other, 0 when equal. */
    public function compare(self $other): int
    {
        $core = [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
        if ($core !== 0 || $this->prerelease === $other->prerelease) {
            return $core;
        }

        if ($this->prerelease === null || $other->prerelease === null) {
            return $this->prerelease === null ? 1 : -1;
        }

        return $this->comparePrerelease(explode('.', $this->prerelease), explode('.', $other->prerelease));
    }

    public function __toString(): string
    {
        $core = "{$this->major}.{$this->minor}.{$this->patch}";

        return $this->prerelease === null ? $core : "{$core}-{$this->prerelease}";
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private function comparePrerelease(array $left, array $right): int
    {
        foreach ($left as $index => $identifier) {
            if (! array_key_exists($index, $right)) {
                return 1;
            }

            $order = $this->compareIdentifier($identifier, $right[$index]);
            if ($order !== 0) {
                return $order;
            }
        }

        return count($left) <=> count($right);
    }

    /** Numeric identifiers compare numerically and rank below alphanumeric ones (semver §11.4). */
    private function compareIdentifier(string $left, string $right): int
    {
        $leftNumeric = ctype_digit($left);
        $rightNumeric = ctype_digit($right);

        if ($leftNumeric && $rightNumeric) {
            return (int) $left <=> (int) $right;
        }

        if ($leftNumeric !== $rightNumeric) {
            return $leftNumeric ? -1 : 1;
        }

        return strcmp($left, $right) <=> 0;
    }
}
