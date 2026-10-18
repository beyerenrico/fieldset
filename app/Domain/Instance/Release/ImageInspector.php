<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

/**
 * Resolves the per-platform manifests and download sizes of a multi-platform image index. Every release image
 * must be an index carrying each requested platform; attestation manifests (buildx SBOM/provenance) are
 * skipped.
 */
final readonly class ImageInspector
{
    public function __construct(private RegistryClient $client) {}

    /**
     * @param  list<string>  $platforms  e.g. ['linux/amd64', 'linux/arm64']
     * @param  string|null  $mirror  read from this registry instead of the image's own (local harness)
     * @return array<string, PlatformImage> platform => image, in the order requested
     */
    public function inspect(ImagePin $image, array $platforms, ?string $mirror = null): array
    {
        $index = $this->client->manifest($mirror ?? $image->registry, $image->repository, $image->digest);
        if (! $index->isIndex()) {
            throw new ReleaseException(sprintf('%s is not a multi-platform image index (%s).', $image->reference(), $index->mediaType));
        }

        $entries = $index->json()['manifests'] ?? [];
        $resolved = [];
        foreach ($platforms as $platform) {
            $digest = self::platformDigest(is_array($entries) ? $entries : [], $platform);
            if ($digest === null) {
                throw new ReleaseException(sprintf('%s has no %s image.', $image->reference(), $platform));
            }

            $manifest = $this->client->manifest($mirror ?? $image->registry, $image->repository, $digest);
            $resolved[$platform] = new PlatformImage($platform, $digest, $this->compressedSize($manifest, $image));
        }

        return $resolved;
    }

    /**
     * The digest of the image manifest for `os/architecture` among an index's entries, attestations skipped.
     *
     * @param  array<mixed>  $entries  the index's `manifests`
     */
    public static function platformDigest(array $entries, string $platform): ?string
    {
        [$os, $architecture] = explode('/', $platform, 2);
        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_array($entry['platform'] ?? null) || ! is_string($entry['digest'] ?? null)) {
                continue;
            }

            $annotations = is_array($entry['annotations'] ?? null) ? $entry['annotations'] : [];
            $isAttestation = ($annotations['vnd.docker.reference.type'] ?? null) === 'attestation-manifest';
            if (! $isAttestation && ($entry['platform']['os'] ?? null) === $os && ($entry['platform']['architecture'] ?? null) === $architecture) {
                return $entry['digest'];
            }
        }

        return null;
    }

    private function compressedSize(RegistryManifest $manifest, ImagePin $image): int
    {
        if (! $manifest->isImage()) {
            throw new ReleaseException(sprintf('%s: %s is not an image manifest (%s).', $image->reference(), $manifest->digest, $manifest->mediaType));
        }

        $data = $manifest->json();
        $blobs = [$data['config'] ?? null, ...(is_array($data['layers'] ?? null) ? $data['layers'] : [])];
        $size = 0;
        foreach ($blobs as $blob) {
            if (! is_array($blob) || ! is_int($blob['size'] ?? null) || $blob['size'] < 0) {
                throw new ReleaseException(sprintf('%s: %s lists a blob without a size.', $image->reference(), $manifest->digest));
            }

            $size += $blob['size'];
        }

        return $size;
    }
}
