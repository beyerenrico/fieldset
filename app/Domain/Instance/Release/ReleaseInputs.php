<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

use JsonException;

/**
 * The committed release inputs in `distribution/release.json`: the app image repository, the release-notes URL
 * template, the oldest version a release upgrades from, and every third-party image pinned by digest. Pins
 * change only by a deliberate commit (R4); a missing service or digest fails manifest generation.
 */
final readonly class ReleaseInputs
{
    public const int SCHEMA_VERSION = 1;

    /** Third-party services of an instance, in manifest order (R4, KTD10, KTD12, KTD13). */
    public const array THIRD_PARTY_SERVICES = ['postgres', 'valkey', 'gotenberg', 'mailpit', 'caddy', 'cloudflared'];

    /**
     * @param  array<string, ImagePin>  $images  third-party service => pin, in THIRD_PARTY_SERVICES order
     */
    public function __construct(
        public string $appRegistry,
        public string $appRepository,
        public string $notesUrlTemplate,
        public ReleaseVersion $upgradesFrom,
        public array $images,
    ) {}

    public static function fromFile(string $path): self
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new ReleaseException(sprintf('Cannot read the release inputs "%s".', $path));
        }

        try {
            $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ReleaseException(sprintf('The release inputs "%s" are not valid JSON.', $path), 0, $exception);
        }

        return self::fromArray(is_array($data) ? $data : [], $path);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data, string $source = 'release inputs'): self
    {
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new ReleaseException(sprintf('%s: schema_version must be %d.', $source, self::SCHEMA_VERSION));
        }

        [$registry, $repository] = self::parseAppImage(self::string($data, 'app_image', $source), $source);
        $notesUrl = self::string($data, 'notes_url', $source);
        if (! str_starts_with($notesUrl, 'https://') || ! str_contains($notesUrl, '{version}')) {
            throw new ReleaseException(sprintf('%s: notes_url must be an https URL containing {version}.', $source));
        }

        $pins = is_array($data['images'] ?? null) ? $data['images'] : [];
        $images = [];
        foreach (self::THIRD_PARTY_SERVICES as $service) {
            $reference = $pins[$service] ?? null;
            if (! is_string($reference) || $reference === '') {
                throw new ReleaseException(sprintf('%s pins no image for the third-party service "%s".', $source, $service));
            }

            $images[$service] = ImagePin::parse($reference);
        }

        return new self($registry, $repository, $notesUrl, ReleaseVersion::parse(self::string($data, 'upgrades_from', $source)), $images);
    }

    public function notesUrl(ReleaseVersion $version): string
    {
        return str_replace('{version}', (string) $version, $this->notesUrlTemplate);
    }

    public function appImage(ReleaseVersion $version, string $digest): ImagePin
    {
        return new ImagePin($this->appRegistry, $this->appRepository, (string) $version, $digest);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function parseAppImage(string $name, string $source): array
    {
        $parts = explode('/', $name, 2);

        try {
            ImagePin::assertRegistry($parts[0]);
            ImagePin::assertRepository($parts[1] ?? '');
        } catch (ReleaseException $exception) {
            throw new ReleaseException(sprintf('%s: app_image must be registry/repository without tag or digest.', $source), 0, $exception);
        }

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function string(array $data, string $key, string $source): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new ReleaseException(sprintf('%s: "%s" must be a non-empty string.', $source, $key));
        }

        return $value;
    }
}
