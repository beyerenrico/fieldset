<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

/** One service image in the release manifest: its pin and the platforms it resolves to. */
final readonly class ManifestImage
{
    /**
     * @param  array<string, PlatformImage>  $platforms  platform => image
     */
    public function __construct(
        public ImagePin $pin,
        public array $platforms,
    ) {}

    /**
     * @param  array<mixed>  $data  one `images.<service>` entry of a schema-valid manifest
     */
    public static function fromArray(array $data): self
    {
        $platforms = [];
        foreach (is_array($data['platforms'] ?? null) ? $data['platforms'] : [] as $platform => $image) {
            $platforms[(string) $platform] = new PlatformImage(
                (string) $platform,
                is_array($image) && is_string($image['digest'] ?? null) ? $image['digest'] : '',
                is_array($image) && is_int($image['compressed_size'] ?? null) ? $image['compressed_size'] : 0,
            );
        }

        return new self(new ImagePin(
            self::string($data, 'registry'),
            self::string($data, 'repository'),
            self::string($data, 'tag'),
            self::string($data, 'digest'),
        ), $platforms);
    }

    /**
     * @return array{registry: string, repository: string, tag: string, digest: string, platforms: array<string, array{digest: string, compressed_size: int}>}
     */
    public function toArray(): array
    {
        return [
            'registry' => $this->pin->registry,
            'repository' => $this->pin->repository,
            'tag' => $this->pin->tag,
            'digest' => $this->pin->digest,
            'platforms' => array_map(static fn (PlatformImage $image): array => $image->toArray(), $this->platforms),
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}
