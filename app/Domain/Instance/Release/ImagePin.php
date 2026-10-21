<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

/**
 * An image pinned by digest: `registry/repository:tag@sha256:…`, always fully qualified (R4). The tag is
 * informational; installs pull `<registry or mirror>/<repository>@<digest>` only (§6.5, ADR 0050).
 */
final readonly class ImagePin
{
    public const string REGISTRY_PATTERN = '^(?:localhost|[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+|\d{1,3}(?:\.\d{1,3}){3})(?::\d{1,5})?$';

    public const string REPOSITORY_PATTERN = '^[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*(?:/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)*$';

    public const string TAG_PATTERN = '^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$';

    public const string DIGEST_PATTERN = '^sha256:[a-f0-9]{64}$';

    public function __construct(
        public string $registry,
        public string $repository,
        public string $tag,
        public string $digest,
    ) {
        self::assertMatches('registry', $registry, self::REGISTRY_PATTERN);
        self::assertMatches('repository', $repository, self::REPOSITORY_PATTERN);
        self::assertMatches('tag', $tag, self::TAG_PATTERN);
        self::assertDigest($digest, $this->name());
    }

    /** Parses `registry/repository:tag@sha256:…`; a reference without a digest is refused. */
    public static function parse(string $reference): self
    {
        [$name, $digest] = array_pad(explode('@', $reference, 2), 2, '');
        if ($digest === '') {
            throw new ReleaseException(sprintf('Image "%s" is not pinned by digest (expected …@sha256:<64 hex>).', $reference));
        }

        $slash = strpos($name, '/');
        $colon = strrpos($name, ':');
        if ($slash === false || $colon === false || $colon < $slash) {
            throw new ReleaseException(sprintf('Image "%s" must be fully qualified as registry/repository:tag@digest.', $reference));
        }

        return new self(substr($name, 0, $slash), substr($name, $slash + 1, $colon - $slash - 1), substr($name, $colon + 1), $digest);
    }

    public static function assertDigest(string $digest, string $subject): void
    {
        if (preg_match('/'.self::DIGEST_PATTERN.'/D', $digest) !== 1) {
            throw new ReleaseException(sprintf('The digest of %s must look like sha256:<64 hex>, got "%s".', $subject, $digest));
        }
    }

    public static function assertRegistry(string $registry): void
    {
        self::assertMatches('registry', $registry, self::REGISTRY_PATTERN);
    }

    public static function assertRepository(string $repository): void
    {
        self::assertMatches('repository', $repository, self::REPOSITORY_PATTERN);
    }

    /** `registry/repository` without tag or digest. */
    public function name(): string
    {
        return "{$this->registry}/{$this->repository}";
    }

    /** `registry/repository:tag@digest`. */
    public function reference(): string
    {
        return "{$this->name()}:{$this->tag}@{$this->digest}";
    }

    /** What an install pulls: `<mirror or registry>/<repository>@<digest>` (FIELDSET_REGISTRY, ADR 0050). */
    public function pullReference(?string $mirror = null): string
    {
        return ($mirror ?? $this->registry)."/{$this->repository}@{$this->digest}";
    }

    private static function assertMatches(string $field, string $value, string $pattern): void
    {
        if (preg_match('/'.str_replace('/', '\/', $pattern).'/D', $value) !== 1) {
            throw new ReleaseException(sprintf('Invalid image %s "%s".', $field, $value));
        }
    }
}
