<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

use JsonException;

/** A manifest or index fetched by digest, with its exact bytes (the digest is verified on fetch). */
final readonly class RegistryManifest
{
    public const array INDEX_TYPES = [
        'application/vnd.oci.image.index.v1+json',
        'application/vnd.docker.distribution.manifest.list.v2+json',
    ];

    public const array IMAGE_TYPES = [
        'application/vnd.oci.image.manifest.v1+json',
        'application/vnd.docker.distribution.manifest.v2+json',
    ];

    public function __construct(
        public string $mediaType,
        public string $body,
        public string $digest,
    ) {}

    public function isIndex(): bool
    {
        return in_array($this->mediaType, self::INDEX_TYPES, true);
    }

    public function isImage(): bool
    {
        return in_array($this->mediaType, self::IMAGE_TYPES, true);
    }

    /**
     * @return array<mixed>
     */
    public function json(): array
    {
        try {
            $data = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ReleaseException(sprintf('The manifest %s is not valid JSON.', $this->digest), 0, $exception);
        }

        return is_array($data) ? $data : [];
    }
}
