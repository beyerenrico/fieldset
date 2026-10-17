<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

/**
 * One platform of a multi-platform image: its manifest digest and the compressed bytes of its config and
 * layers, which is what a pull downloads (used for disk checks and pull-time estimates).
 */
final readonly class PlatformImage
{
    /** Platforms a release supports (ADR 0051). */
    public const array SUPPORTED = ['linux/amd64', 'linux/arm64'];

    public function __construct(
        public string $platform,
        public string $digest,
        public int $compressedSize,
    ) {
        if (! in_array($platform, self::SUPPORTED, true)) {
            throw new ReleaseException(sprintf('Unsupported platform "%s" (supported: %s).', $platform, implode(', ', self::SUPPORTED)));
        }

        ImagePin::assertDigest($digest, "the {$platform} manifest");
        if ($compressedSize < 1) {
            throw new ReleaseException(sprintf('The %s image size must be positive.', $platform));
        }
    }

    /**
     * @return array{digest: string, compressed_size: int}
     */
    public function toArray(): array
    {
        return ['digest' => $this->digest, 'compressed_size' => $this->compressedSize];
    }
}
