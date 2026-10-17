<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/** A local image as `docker image inspect` describes it. */
final readonly class ImageInfo
{
    /**
     * @param  list<string>  $repoDigests  e.g. ghcr.io/x/app@sha256:…
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public string $id,
        public array $repoDigests = [],
        public array $labels = [],
        public ?string $architecture = null,
        public ?string $os = null,
    ) {}

    /** @param  array<string, mixed>  $json  one document of `docker image inspect --format '{{json .}}'` */
    public static function fromInspect(array $json): self
    {
        $config = is_array($json['Config'] ?? null) ? $json['Config'] : [];
        $digests = is_array($json['RepoDigests'] ?? null) ? array_values(array_filter($json['RepoDigests'], is_string(...))) : [];

        return new self(
            id: is_string($json['Id'] ?? null) ? $json['Id'] : '',
            repoDigests: $digests,
            labels: Labels::parse($config['Labels'] ?? null),
            architecture: is_string($json['Architecture'] ?? null) ? $json['Architecture'] : null,
            os: is_string($json['Os'] ?? null) ? $json['Os'] : null,
        );
    }

    /** Whether the image is known locally under this exact `repository@sha256:…` reference. */
    public function hasDigest(string $reference): bool
    {
        return in_array($reference, $this->repoDigests, true);
    }
}
