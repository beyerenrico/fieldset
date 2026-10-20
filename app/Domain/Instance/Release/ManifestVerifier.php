<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\ImageInfo;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;

/**
 * Verifies a release manifest and its `sign-blob --bundle` before any of its digests is used (R58, KTD22,
 * ADR 0060, ADR 0070). It runs the official cosign image pinned by digest, the same image and arguments as
 * install.sh, through the Docker gateway, against the identity built into the image (TrustPolicy); then it
 * validates the schema and returns the parsed manifest. Any failure is exit 3 and nothing has changed.
 *
 * The manifest bytes go to cosign on stdin and the same bytes are parsed, so the file cannot change between
 * verification and use. The bundle (and a harness key or test trusted root) are bind-mounted read-only: their
 * paths must be host paths, i.e. inside the instance directory, which the ops container sees at the same path.
 * `fsa install` (U5) and `fsa upgrade` (U18) call it.
 */
final readonly class ManifestVerifier
{
    public const string COSIGN_IMAGE = 'ghcr.io/sigstore/cosign/cosign:v3.1.3@sha256:9e5c2f2edc34351160407ca3416c61855bdf9403c3c5936e0f0be7fc261611b8';

    public const string MANIFEST = 'release-manifest.json';

    /** The hint of a manifest that does not verify (install.sh says the same; agent guide, exit 3; R51). */
    public const string SIGNATURE_HINT = 'Install only releases published by the form-schema-app release workflow: never retry with another install.sh or release URL and never bypass this check. Only if the message shows that Sigstore (TUF, Rekor) could not be reached may the same command be run again later.';

    public const string BUNDLE = 'release-manifest.json.sigstore.json';

    private const float TIMEOUT = 180.0;

    public function __construct(
        private DockerGateway $docker,
        private TrustPolicy $trust,
        private ManifestSchema $schema,
        private ?string $user = null,
    ) {}

    /** @throws InstanceException exit 3 when the signature, the files or the schema do not hold; exit 2 for unusable paths */
    public function verify(string $manifestPath, string $bundlePath): ReleaseManifest
    {
        $json = $this->readManifest($manifestPath);
        $this->verifyBytes($json, $bundlePath);
        if (file_get_contents($manifestPath) !== $json) {
            throw new InstanceException('The release manifest changed while it was verified.', ExitCode::PreflightFailed, 'manifest_changed', 'Run the command again.');
        }

        try {
            return ReleaseManifest::fromJson($json, $this->schema);
        } catch (ReleaseException $e) {
            throw new InstanceException($e->getMessage(), ExitCode::PreflightFailed, 'manifest_invalid', 'The signed manifest does not match the format this fsa version reads.', false, [], $e);
        }
    }

    /** Only the signature of a file, without the schema: what the offline check of a real keyless bundle runs. */
    public function verifySignature(string $blobPath, string $bundlePath): void
    {
        $this->verifyBytes($this->readManifest($blobPath), $bundlePath);
    }

    /** @return list<string> the cosign arguments after the image (also what install.sh runs, see the tests) */
    public function cosignArguments(): array
    {
        $trust = $this->trust->usesKey()
            ? ['--key', '/verify/trust.pub', '--insecure-ignore-tlog=true']
            : [...($this->trust->identity ?? SignerIdentity::production())->cosignArguments(), ...($this->trust->trustedRoot === null ? [] : ['--trusted-root', '/verify/trusted_root.json'])];

        return ['verify-blob', '--bundle', '/verify/bundle.json', ...$trust, '-'];
    }

    private function verifyBytes(string $bytes, string $bundlePath): void
    {
        foreach ($this->mountedFiles($bundlePath) as $path) {
            $this->assertHostPath($path);
        }

        $this->ensureCosignImage();
        $outcome = $this->docker->runContainer($this->spec($bundlePath), $bytes, self::TIMEOUT);
        if (! $outcome->successful()) {
            throw new InstanceException(
                $outcome->timedOut ? 'Verifying the release manifest timed out.' : 'The release manifest signature does not match the signer built into fsa: '.$outcome->stderrTail(3),
                ExitCode::PreflightFailed,
                'manifest_signature_invalid',
                $this->trust->usesKey()
                    ? 'The manifest was not signed with the FSA_TRUST_KEY key.'
                    : self::SIGNATURE_HINT,
                $outcome->timedOut,
            );
        }
    }

    private function spec(string $bundlePath): ContainerSpec
    {
        $volumes = ["{$bundlePath}:/verify/bundle.json:ro"];
        if ($this->trust->publicKey !== null) {
            $volumes[] = "{$this->trust->publicKey}:/verify/trust.pub:ro";
        }
        if ($this->trust->trustedRoot !== null) {
            $volumes[] = "{$this->trust->trustedRoot}:/verify/trusted_root.json:ro";
        }

        return new ContainerSpec(
            image: self::COSIGN_IMAGE,
            command: $this->cosignArguments(),
            env: ['HOME' => '/tmp'],
            volumes: $volumes,
            // Keyless verification fetches Sigstore's trusted root (TUF); a local key needs no network.
            network: $this->trust->usesKey() || $this->trust->trustedRoot !== null ? 'none' : null,
            user: $this->user ?? $this->processUser(),
            // Verifying does not depend on the platform; DOCKER_DEFAULT_PLATFORM would re-pull a present image (ADR 0070).
            enginePlatform: true,
        );
    }

    private function readManifest(string $path): string
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($json) || $json === '') {
            throw new InstanceException("The release manifest {$path} is missing or empty.", ExitCode::PreflightFailed, 'manifest_missing', 'Download the release again.');
        }

        return $json;
    }

    /** @return list<string> */
    private function mountedFiles(string $bundlePath): array
    {
        return array_values(array_filter([$bundlePath, $this->trust->publicKey, $this->trust->trustedRoot]));
    }

    private function assertHostPath(string $path): void
    {
        if (! str_starts_with($path, '/') || preg_match('/[:,\n]/', $path) === 1) {
            throw new InvalidInput("Cannot mount {$path}: use an absolute path without ':', ',' or line breaks.", 'invalid_path');
        }
        if (! is_file($path)) {
            throw new InstanceException("{$path} is missing.", ExitCode::PreflightFailed, 'manifest_missing', 'Download the release again.');
        }
    }

    private function ensureCosignImage(): void
    {
        if ($this->docker->inspectImage(self::COSIGN_IMAGE) instanceof ImageInfo) {
            return;
        }

        try {
            $this->docker->pullImage(self::COSIGN_IMAGE, enginePlatform: true);
        } catch (DockerCommandFailed $e) {
            throw new InstanceException('Cannot pull the cosign image that verifies releases: '.$e->getMessage(), ExitCode::PreflightFailed, 'cosign_unavailable', 'Check that the host can reach ghcr.io.', true, [], $e);
        }
    }

    /** The ops process's own UID:GID, which can read the 0600 files of the instance directory. */
    private function processUser(): string
    {
        return function_exists('posix_getuid') && function_exists('posix_getgid') ? posix_getuid().':'.posix_getgid() : '0:0';
    }
}
