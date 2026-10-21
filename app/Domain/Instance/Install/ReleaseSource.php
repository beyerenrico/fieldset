<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Release\ManifestImage;
use App\Domain\Instance\Release\ManifestVerifier;
use App\Domain\Instance\Release\ReleaseManifest;
use App\Domain\Instance\State\InstanceDirectory;

/**
 * The release an install uses (R9, R58, ADR 0070). install.sh verified and stored the manifest under
 * `<dir>/releases/<version>/` and names it in FIELDSET_RELEASE_MANIFEST; fieldset verifies it again with the pinned cosign
 * image before it uses any digest, so a manifest swapped after install.sh cannot pick the images that get the
 * socket. A re-run through the shim (no FIELDSET_RELEASE_MANIFEST) uses the stored release of `--version`, or the
 * only one there is.
 */
final readonly class ReleaseSource
{
    public const string RELEASES_DIR = 'releases';

    public function __construct(private ManifestVerifier $verifier) {}

    /** @throws InstanceException exit 2 without a usable manifest, exit 3 when verification fails */
    public function verified(InstanceDirectory $directory, HostFacts $facts, ?string $version): ReleaseManifest
    {
        $path = $this->locate($directory, $facts, $version);
        $manifest = $this->verifier->verify($path, $path.'.sigstore.json');
        if ($version !== null && (string) $manifest->version !== $version) {
            throw new InstanceException("The verified release manifest is for {$manifest->version}, not --version {$version}.", ExitCode::PreflightFailed, 'release_mismatch', 'Run install.sh with the same --version, or omit --version.');
        }

        return $manifest;
    }

    /**
     * The image references to run, `<mirror or registry>/<repository>@<digest>` (what install.sh pulled).
     *
     * @return array<string, string> image role => reference
     */
    public static function digests(ReleaseManifest $manifest, ?string $mirror): array
    {
        return array_map(static fn (ManifestImage $image): string => $image->pin->pullReference($mirror), $manifest->images);
    }

    private function locate(InstanceDirectory $directory, HostFacts $facts, ?string $version): string
    {
        $given = $facts->releaseManifest;
        if ($given !== null) {
            if (! str_starts_with($given, $directory->root.'/')) {
                throw new InvalidInput('FIELDSET_RELEASE_MANIFEST must point into the instance directory.', 'manifest_outside_instance', 'Run install.sh, which stores the verified release in <dir>/releases/.');
            }

            return $given;
        }
        $candidates = $version !== null ? [$version] : $directory->files()->list(self::RELEASES_DIR);
        $relative = count($candidates) === 1 ? self::RELEASES_DIR.'/'.$candidates[0].'/'.ManifestVerifier::MANIFEST : null;
        if ($relative === null || ! $directory->exists($relative)) {
            throw new InvalidInput('No release manifest to install from.', 'manifest_required', 'Run install.sh (it downloads and verifies the release), or pass --version of a release stored in <dir>/releases/.');
        }

        return $directory->path($relative);
    }
}
