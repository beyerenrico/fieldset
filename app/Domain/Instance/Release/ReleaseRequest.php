<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

/** What `release:manifest` builds a manifest from. */
final readonly class ReleaseRequest
{
    /**
     * @param  list<string>  $platforms  platforms every image must carry (default: both supported ones)
     * @param  list<string>  $assets  paths of release assets to checksum (install.sh, shim, stack file, …)
     * @param  string|null  $mirror  registry every image is read from instead of its own (local harness)
     * @param  ReleaseVersion|null  $upgradesFrom  overrides the committed oldest upgrade source
     */
    public function __construct(
        public ReleaseVersion $version,
        public string $appDigest,
        public ReleaseInputs $inputs,
        public array $platforms = PlatformImage::SUPPORTED,
        public array $assets = [],
        public ?string $mirror = null,
        public ?ReleaseVersion $upgradesFrom = null,
    ) {}
}
