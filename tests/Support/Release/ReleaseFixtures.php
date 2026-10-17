<?php

declare(strict_types=1);

namespace Tests\Support\Release;

use App\Domain\Instance\Release\ManifestImage;
use App\Domain\Instance\Release\ManifestVerifier;
use App\Domain\Instance\Release\PlatformImage;
use App\Domain\Instance\Release\ReleaseInputs;
use App\Domain\Instance\Release\ReleaseManifest;
use App\Domain\Instance\Release\ReleaseVersion;

/** Schema-valid release manifests without a registry, for verifier and install-script tests (U4). */
final class ReleaseFixtures
{
    public const string APP_DIGEST = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public static function manifest(string $version = '2.0.0-alpha.1', string $appDigest = self::APP_DIGEST): ReleaseManifest
    {
        $root = dirname(__DIR__, 3);
        $inputs = ReleaseInputs::fromFile($root.'/distribution/release.json');
        $platforms = ['linux/amd64', 'linux/arm64'];
        $images = [];
        foreach (['app' => $inputs->appImage(ReleaseVersion::parse($version), $appDigest), ...$inputs->images] as $service => $pin) {
            $images[$service] = new ManifestImage($pin, array_combine($platforms, array_map(
                static fn (string $platform): PlatformImage => new PlatformImage($platform, 'sha256:'.hash('sha256', $service.$platform), 1000),
                $platforms,
            )));
        }

        $assets = [];
        foreach (ReleaseManifest::REQUIRED_ASSETS as $asset) {
            $assets[$asset] = ['sha256' => (string) hash_file('sha256', "{$root}/distribution/{$asset}"), 'size' => (int) filesize("{$root}/distribution/{$asset}")];
        }

        return new ReleaseManifest(ReleaseVersion::parse($version), ReleaseVersion::parse('2.0.0-alpha.1'), 'https://example.com/notes', $platforms, $images, $assets);
    }

    /**
     * Writes a manifest and a placeholder bundle (not a real signature) into a directory.
     *
     * @return array{0: string, 1: string} manifest path, bundle path
     */
    public static function writeSignedManifest(string $directory, string $version = '2.0.0-alpha.1'): array
    {
        $manifest = "{$directory}/".ManifestVerifier::MANIFEST;
        $bundle = "{$directory}/".ManifestVerifier::BUNDLE;
        file_put_contents($manifest, self::manifest($version)->toJson());
        file_put_contents($bundle, '{"mediaType":"application/vnd.dev.sigstore.bundle.v0.3+json"}');

        return [$manifest, $bundle];
    }
}
