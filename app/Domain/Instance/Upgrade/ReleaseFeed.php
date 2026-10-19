<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Config\ConfigShow;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Install\ReleaseSource;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Release\BuildFlavor;
use App\Domain\Instance\Release\ManifestVerifier;
use App\Domain\Instance\Release\ReleaseManifest;
use App\Domain\Instance\Release\ReleaseVersion;
use App\Domain\Instance\State\InstanceDirectory;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The target release of `fsa upgrade` (R33, R58, KTD19 step 1, plan U18, ADR 0086). The installed fsa downloads the
 * target's manifest and its `sign-blob` bundle from the release URL (GitHub Releases, or FSA_RELEASE_URL: install.sh's
 * mirror, http only for harness builds), `download/v<version>/` for `--to`, `latest/download/` for the newest stable
 * release, into a fresh `releases/.download-*` directory, verifies them with the pinned cosign image against the identity
 * built into the image (ManifestVerifier), and only then stores them under `releases/<version>/`, where install.sh keeps
 * the installed release. A stored release that is already there is verified again, never trusted as it is.
 */
final readonly class ReleaseFeed
{
    /** A manifest is a few KiB; anything larger is not one. */
    private const int MAX_BYTES = 1_048_576;

    public function __construct(private ManifestVerifier $verifier) {}

    /**
     * @throws InstanceException exit 2 (no such release, an unusable URL), 3 (unreachable, signature, mismatch)
     */
    public function fetch(InstanceDirectory $directory, HostFacts $facts, ?string $version): ReleaseManifest
    {
        $base = self::base($facts).($version === null ? '/latest/download' : '/download/v'.$version);
        $staging = ReleaseSource::RELEASES_DIR.'/.download-'.bin2hex(random_bytes(6));
        $directory->ensureDirectory(ReleaseSource::RELEASES_DIR);
        $directory->ensureDirectory($staging);

        try {
            $directory->write($staging.'/'.ManifestVerifier::MANIFEST, $this->download($base.'/'.ManifestVerifier::MANIFEST, $version, true), 0644);
            $directory->write($staging.'/'.ManifestVerifier::BUNDLE, $this->download($base.'/'.ManifestVerifier::BUNDLE, $version, false), 0644);
            $manifest = $this->verifier->verify($directory->path($staging.'/'.ManifestVerifier::MANIFEST), $directory->path($staging.'/'.ManifestVerifier::BUNDLE));
            if ($version !== null && (string) $manifest->version !== $version) {
                throw new InstanceException("The release server answered --to {$version} with the manifest of {$manifest->version}.", ExitCode::PreflightFailed, 'release_mismatch', 'Check FSA_RELEASE_URL; nothing was changed.');
            }
            $this->store($directory, $staging, (string) $manifest->version);

            return $manifest;
        } finally {
            $this->remove($directory->path($staging));
        }
    }

    /** The release URL: FSA_RELEASE_URL (https; http only in a harness build) or GitHub Releases. */
    public static function base(HostFacts $facts): string
    {
        $url = rtrim($facts->releaseUrl ?? ConfigShow::RELEASES_URL, '/');
        if (stripos($url, 'https://') !== 0 && BuildFlavor::current() !== BuildFlavor::Harness) {
            throw new InvalidInput('FSA_RELEASE_URL must be an https URL.', 'insecure_release_url', 'Unset FSA_RELEASE_URL to use GitHub Releases, or point it at an https mirror.');
        }

        return $url;
    }

    private function download(string $url, ?string $version, bool $manifest): string
    {
        try {
            $response = Http::timeout(60)->connectTimeout(15)
                ->withOptions(['allow_redirects' => ['max' => 5, 'protocols' => stripos($url, 'http://') === 0 ? ['http', 'https'] : ['https']]])
                ->get($url);
        } catch (Throwable $e) {
            throw new InstanceException("Cannot download {$url}: ".$e->getMessage(), ExitCode::PreflightFailed, 'release_unavailable', 'Check the network and FSA_RELEASE_URL, then run `fsa upgrade` again; nothing was changed.', true, [], $e);
        }
        if (in_array($response->status(), [404, 410], true) && $manifest) {
            throw $version === null
                ? new InvalidInput('There is no latest release to upgrade to (pre-releases are never "latest").', 'no_latest_release', 'Name the version: `fsa upgrade --to <version>`.')
                : new InvalidInput("There is no release {$version}.", 'release_not_found', 'Check the version against the releases page; nothing was changed.');
        }
        $body = $response->body();
        if (! $response->successful() || $body === '' || strlen($body) > self::MAX_BYTES) {
            throw new InstanceException("Cannot download {$url} (HTTP {$response->status()}).", ExitCode::PreflightFailed, 'release_unavailable', 'Check the network and FSA_RELEASE_URL, then run `fsa upgrade` again; nothing was changed.', true);
        }

        return $body;
    }

    /** Moves the verified pair into `releases/<version>/` (replacing what an earlier download left there). */
    private function store(InstanceDirectory $directory, string $staging, string $version): void
    {
        ReleaseVersion::parse($version); // the verified manifest's own version: a plain directory name
        $target = ReleaseSource::RELEASES_DIR.'/'.$version;
        $directory->ensureDirectory($target);
        foreach ([ManifestVerifier::MANIFEST, ManifestVerifier::BUNDLE] as $file) {
            $directory->write($target.'/'.$file, (string) $directory->read($staging.'/'.$file), 0644);
        }
    }

    private function remove(string $path): void
    {
        foreach (glob($path.'/{,.}[!.]*', GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($path);
    }
}
