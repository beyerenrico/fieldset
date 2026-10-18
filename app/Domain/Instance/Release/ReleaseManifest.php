<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

use JsonException;

/**
 * The release manifest (R2, R4, ADR 0060): version, channel, release-notes URL, oldest upgrade source,
 * supported platforms, every service image by digest with per-platform sizes, and asset checksums. It is signed
 * with `cosign sign-blob --bundle` and carries no signer identity: install and upgrade verify it against an
 * identity built into `install.sh` and the image (R58). Readers ignore unknown keys (KTD6).
 */
final readonly class ReleaseManifest
{
    public const int SCHEMA_VERSION = 1;

    /** Every service an instance runs, in manifest order. */
    public const array SERVICES = ['app', ...ReleaseInputs::THIRD_PARTY_SERVICES];

    public const string ASSET_PATTERN = '^[A-Za-z0-9][A-Za-z0-9._-]*$';

    /** Assets every release ships (U4): the bootstrap script and the shim it installs. */
    public const array REQUIRED_ASSETS = ['install.sh', 'shim.sh'];

    /**
     * @param  list<string>  $platforms
     * @param  array<string, ManifestImage>  $images  service => image
     * @param  array<string, array{sha256: string, size: int}>  $assets  file name => checksum
     */
    public function __construct(
        public ReleaseVersion $version,
        public ReleaseVersion $upgradesFrom,
        public string $notesUrl,
        public array $platforms,
        public array $images,
        public array $assets,
    ) {
        $this->assertConsistent();
    }

    /** Parses a manifest after validating it against the schema. */
    public static function fromJson(string $json, ManifestSchema $schema): self
    {
        $schema->assertValid($json);

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ReleaseException('The release manifest is not valid JSON.', 0, $exception);
        }

        $data = is_array($data) ? $data : [];
        $images = [];
        foreach (is_array($data['images'] ?? null) ? $data['images'] : [] as $service => $image) {
            $images[(string) $service] = ManifestImage::fromArray(is_array($image) ? $image : []);
        }

        /** @var array<string, array{sha256: string, size: int}> $assets */
        $assets = is_array($data['assets'] ?? null) ? $data['assets'] : [];
        /** @var list<string> $platforms */
        $platforms = is_array($data['platforms'] ?? null) ? array_values($data['platforms']) : [];

        return new self(
            ReleaseVersion::parse(is_string($data['version'] ?? null) ? $data['version'] : ''),
            ReleaseVersion::parse(is_string($data['upgrades_from'] ?? null) ? $data['upgrades_from'] : ''),
            is_string($data['notes_url'] ?? null) ? $data['notes_url'] : '',
            $platforms,
            $images,
            $assets,
        );
    }

    public function channel(): ReleaseChannel
    {
        return $this->version->channel();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'version' => (string) $this->version,
            'channel' => $this->channel()->value,
            'notes_url' => $this->notesUrl,
            'upgrades_from' => (string) $this->upgradesFrom,
            'platforms' => $this->platforms,
            'images' => array_map(static fn (ManifestImage $image): array => $image->toArray(), $this->images),
            'assets' => $this->assets,
        ];
    }

    /** Pretty JSON (4-space indent, the layout install.sh reads) with a trailing newline. */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private function assertConsistent(): void
    {
        if ($this->upgradesFrom->compare($this->version) > 0) {
            throw new ReleaseException(sprintf('Release %s cannot upgrade from the newer version %s.', $this->version, $this->upgradesFrom));
        }

        if (! str_starts_with($this->notesUrl, 'https://')) {
            throw new ReleaseException('The release-notes URL must use https.');
        }

        $this->assertPlatforms();
        $expected = $this->platforms;
        sort($expected);
        foreach (self::SERVICES as $service) {
            $image = $this->images[$service] ?? null;
            if ($image === null) {
                throw new ReleaseException(sprintf('The manifest lists no image for the service "%s".', $service));
            }

            $listed = array_keys($image->platforms);
            sort($listed);
            if ($listed !== $expected) {
                throw new ReleaseException(sprintf('The "%s" image must list exactly the platforms %s.', $service, implode(', ', $this->platforms)));
            }
        }

        foreach (self::REQUIRED_ASSETS as $required) {
            if (! array_key_exists($required, $this->assets)) {
                throw new ReleaseException(sprintf('A release ships the asset "%s".', $required));
            }
        }

        foreach ($this->assets as $name => $asset) {
            if (preg_match('/'.self::ASSET_PATTERN.'/D', $name) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $asset['sha256']) !== 1 || $asset['size'] < 0) {
                throw new ReleaseException(sprintf('Invalid asset entry "%s".', $name));
            }
        }
    }

    private function assertPlatforms(): void
    {
        if ($this->platforms === [] || array_unique($this->platforms) !== $this->platforms) {
            throw new ReleaseException('A release lists one or more distinct platforms.');
        }

        foreach ($this->platforms as $platform) {
            if (! in_array($platform, PlatformImage::SUPPORTED, true)) {
                throw new ReleaseException(sprintf('Unsupported platform "%s".', $platform));
            }
        }
    }
}
