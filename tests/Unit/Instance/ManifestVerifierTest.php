<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\ImageInfo;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Release\BuildFlavor;
use App\Domain\Instance\Release\ManifestSchema;
use App\Domain\Instance\Release\ManifestVerifier;
use App\Domain\Instance\Release\ReleaseException;
use App\Domain\Instance\Release\SignerIdentity;
use App\Domain\Instance\Release\TrustPolicy;
use Tests\Support\Instance\RecordingDockerGateway;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Release\ReleaseFixtures;

/*
 * U4 / M9-T07: ManifestVerifier verifies a release manifest with the pinned cosign image through the Docker
 * gateway, against the identity built into the image, before any digest is used (R58, KTD22, ADR 0070).
 * The real cosign runs in tests/Install/Journeys/BootstrapJourneyTest.php.
 */

beforeEach(function (): void {
    $this->docker = new RecordingDockerGateway;
    $this->directory = TemporaryInstance::directory();
    [$this->manifest, $this->bundle] = ReleaseFixtures::writeSignedManifest($this->directory->path());
});

afterEach(fn () => TemporaryInstance::cleanup());

function manifestVerifier(RecordingDockerGateway $docker, TrustPolicy $trust): ManifestVerifier
{
    return new ManifestVerifier($docker, $trust, ManifestSchema::bundled(), '501:20');
}

it('runs the pinned cosign image against the anchored production identity and returns the manifest', function (): void {
    $this->docker->withImage(ManifestVerifier::COSIGN_IMAGE, new ImageInfo('sha256:'.str_repeat('1', 64), [ManifestVerifier::COSIGN_IMAGE]));

    $manifest = manifestVerifier($this->docker, TrustPolicy::keyless(SignerIdentity::production()))->verify($this->manifest, $this->bundle);
    $call = $this->docker->called('runContainer')[0];
    $spec = $call['spec'];

    expect((string) $manifest->version)->toBe('2.0.0-alpha.1')
        ->and($this->docker->called('pullImage'))->toBe([])
        ->and($spec->image)->toBe('ghcr.io/sigstore/cosign/cosign:v3.1.3@sha256:9e5c2f2edc34351160407ca3416c61855bdf9403c3c5936e0f0be7fc261611b8')
        ->and($spec->command)->toBe([
            'verify-blob', '--bundle', '/verify/bundle.json',
            '--certificate-identity-regexp', '^https://github\.com/beyerenrico/fieldset/\.github/workflows/release\.yml@refs/tags/v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-(alpha|beta|rc)(\.(0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?$',
            '--certificate-oidc-issuer', 'https://token.actions.githubusercontent.com',
            '--certificate-github-workflow-trigger', 'push',
            '-',
        ])
        ->and($spec->volumes)->toBe(["{$this->bundle}:/verify/bundle.json:ro"])
        ->and($spec->network)->toBeNull()
        ->and($spec->user)->toBe('501:20')
        ->and($spec->env)->toBe(['HOME' => '/tmp'])
        ->and($spec->remove)->toBeTrue()
        ->and($spec->enginePlatform)->toBeTrue()
        ->and($call['input'])->toBe(file_get_contents($this->manifest));
});

it('pulls the cosign image only when it is missing', function (): void {
    manifestVerifier($this->docker, TrustPolicy::keyless(SignerIdentity::production()))->verify($this->manifest, $this->bundle);

    expect($this->docker->called('pullImage'))->toBe([['reference' => ManifestVerifier::COSIGN_IMAGE, 'enginePlatform' => true]])
        ->and(array_slice($this->docker->methods(), 0, 3))->toBe(['inspectImage', 'pullImage', 'runContainer']);
});

it('fails with exit 3 when the signature does not verify, before returning any digest', function (): void {
    $this->docker->queueOutcome('runContainer', new ProcessOutcome(1, '', "Error: no matching CertificateIdentity found\nerror during command execution"));

    try {
        manifestVerifier($this->docker, TrustPolicy::keyless(SignerIdentity::production()))->verify($this->manifest, $this->bundle);
        $this->fail('verification passed');
    } catch (InstanceException $e) {
        expect($e->exitCode)->toBe(ExitCode::PreflightFailed)
            ->and($e->errorCode)->toBe('manifest_signature_invalid')
            ->and($e->getMessage())->toContain('no matching CertificateIdentity')
            ->and($e->hint)->toContain('release workflow')
            ->and($e->retryable)->toBeFalse();
    }
});

it('says in its hint what the agent guide says: stop, the same command again only when Sigstore could not be reached (R51)', function (): void {
    $this->docker->queueOutcome('runContainer', new ProcessOutcome(1, '', 'Error: no matching CertificateIdentity found'));

    try {
        manifestVerifier($this->docker, TrustPolicy::keyless(SignerIdentity::production()))->verify($this->manifest, $this->bundle);
        $this->fail('verification passed');
    } catch (InstanceException $e) {
        expect($e->hint)->toBe(ManifestVerifier::SIGNATURE_HINT)
            ->and($e->hint)->toContain('never retry with another install.sh or release URL')->toContain('never bypass')
            ->and($e->hint)->toContain('Only if the message shows that Sigstore (TUF, Rekor) could not be reached')
            ->and($e->hint)->not->toContain('retry later');
    }
});

it('reports a timeout as a retryable exit 3', function (): void {
    $this->docker->queueOutcome('runContainer', new ProcessOutcome(124, '', '', true));

    expect(fn () => manifestVerifier($this->docker, TrustPolicy::keyless(SignerIdentity::production()))->verify($this->manifest, $this->bundle))
        ->toThrow(fn (InstanceException $e) => expect([$e->exitCode, $e->retryable, $e->getMessage()])->toBe([ExitCode::PreflightFailed, true, 'Verifying the release manifest timed out.']));
});

it('maps a failed cosign pull to a retryable exit 3', function (): void {
    $this->docker->failOn('pullImage', new DockerCommandFailed('pull', new ProcessOutcome(1, '', 'denied')));

    expect(fn () => manifestVerifier($this->docker, TrustPolicy::keyless(SignerIdentity::production()))->verify($this->manifest, $this->bundle))
        ->toThrow(fn (InstanceException $e) => expect([$e->exitCode, $e->errorCode, $e->retryable])->toBe([ExitCode::PreflightFailed, 'cosign_unavailable', true]))
        ->and($this->docker->called('runContainer'))->toBe([]);
});

it('fails with exit 3 for a missing manifest or bundle and exit 2 for an unmountable path', function (): void {
    $verifier = manifestVerifier($this->docker, TrustPolicy::keyless(SignerIdentity::production()));

    expect(fn () => $verifier->verify($this->manifest.'.missing', $this->bundle))
        ->toThrow(fn (InstanceException $e) => expect([$e->exitCode, $e->errorCode])->toBe([ExitCode::PreflightFailed, 'manifest_missing']))
        ->and(fn () => $verifier->verify($this->manifest, $this->bundle.'.missing'))
        ->toThrow(fn (InstanceException $e) => expect($e->errorCode)->toBe('manifest_missing'))
        ->and(fn () => $verifier->verify($this->manifest, 'relative/bundle.json'))->toThrow(InvalidInput::class, 'absolute path')
        ->and(fn () => $verifier->verify($this->manifest, '/tmp/a:b/bundle.json'))->toThrow(InvalidInput::class)
        ->and($this->docker->called('runContainer'))->toBe([]);
});

it('refuses a signed manifest that does not match the schema (exit 3)', function (): void {
    file_put_contents($this->manifest, '{"schema_version": 2}');

    expect(fn () => manifestVerifier($this->docker, TrustPolicy::keyless(SignerIdentity::production()))->verify($this->manifest, $this->bundle))
        ->toThrow(fn (InstanceException $e) => expect([$e->exitCode, $e->errorCode, $e->getPrevious()::class])
            ->toBe([ExitCode::PreflightFailed, 'manifest_invalid', ReleaseException::class]));
});

it('verifies the bytes it parses: a manifest changed during verification fails', function (): void {
    $file = $this->manifest;
    $this->docker->beforeRun = function () use ($file): void {
        file_put_contents($file, str_replace('2.0.0-alpha.1', '2.0.0-alpha.2', (string) file_get_contents($file)));
    };

    expect(fn () => manifestVerifier($this->docker, TrustPolicy::keyless(SignerIdentity::production()))->verify($this->manifest, $this->bundle))
        ->toThrow(fn (InstanceException $e) => expect($e->errorCode)->toBe('manifest_changed'));
});

it('uses a local key without network only through the harness build', function (): void {
    $key = $this->directory->path().'/cosign.pub';
    file_put_contents($key, "-----BEGIN PUBLIC KEY-----\n-----END PUBLIC KEY-----\n");
    $harnessFlavor = $this->directory->path().'/flavor';
    file_put_contents($harnessFlavor, "harness\n");
    $trust = TrustPolicy::fromEnvironment(['FIELDSET_TRUST_KEY' => $key], BuildFlavor::current($harnessFlavor));

    manifestVerifier($this->docker, $trust)->verify($this->manifest, $this->bundle);
    $spec = $this->docker->called('runContainer')[0]['spec'];

    expect($spec->command)->toBe(['verify-blob', '--bundle', '/verify/bundle.json', '--key', '/verify/trust.pub', '--insecure-ignore-tlog=true', '-'])
        ->and($spec->volumes)->toBe(["{$this->bundle}:/verify/bundle.json:ro", "{$key}:/verify/trust.pub:ro"])
        ->and($spec->network)->toBe('none')
        ->and(fn () => TrustPolicy::fromEnvironment(['FIELDSET_TRUST_KEY' => $key], BuildFlavor::Production))
        ->toThrow(fn (InvalidInput $e) => expect([$e->exitCode, $e->errorCode])->toBe([ExitCode::InvalidInput, 'trust_key_not_allowed']))
        ->and(fn () => TrustPolicy::fromEnvironment(['FIELDSET_TRUST_KEY' => 'cosign.pub'], BuildFlavor::Harness))->toThrow(InvalidInput::class, 'absolute path');
});

it('mounts a pinned trusted root for offline keyless verification and adds no network', function (): void {
    $root = $this->directory->path().'/trusted_root.json';
    file_put_contents($root, '{}');

    manifestVerifier($this->docker, TrustPolicy::keyless(SignerIdentity::githubWorkflow('goreleaser/goreleaser', 'release.yml'), $root))->verify($this->manifest, $this->bundle);
    $spec = $this->docker->called('runContainer')[0]['spec'];

    expect(array_slice($spec->command, -3))->toBe(['--trusted-root', '/verify/trusted_root.json', '-'])
        ->and($spec->command[4])->toStartWith('^https://github\.com/goreleaser/goreleaser/\.github/workflows/release\.yml@refs/tags/v')
        ->and($spec->volumes[1])->toBe("{$root}:/verify/trusted_root.json:ro")
        ->and($spec->network)->toBe('none');
});

it('defaults to the keyless production identity and treats only an explicit harness file as harness', function (): void {
    $file = $this->directory->path().'/flavor';

    expect(TrustPolicy::fromEnvironment([], BuildFlavor::Harness)->identity)->toEqual(SignerIdentity::production())
        ->and(TrustPolicy::fromEnvironment(['FIELDSET_TRUST_KEY' => ''], BuildFlavor::Production)->usesKey())->toBeFalse()
        ->and(BuildFlavor::current($file))->toBe(BuildFlavor::Production);

    file_put_contents($file, "Harness\n");
    expect(BuildFlavor::current($file))->toBe(BuildFlavor::Production);
    file_put_contents($file, "harness\n");
    expect(BuildFlavor::current($file))->toBe(BuildFlavor::Harness)
        ->and(BuildFlavor::current())->toBe(BuildFlavor::Production)
        ->and(BuildFlavor::Harness->acceptsTrustKey())->toBeTrue()
        ->and(BuildFlavor::Production->acceptsTrustKey())->toBeFalse();
});

it('pins the production identity to this repository and release workflow on a release tag', function (): void {
    $identity = SignerIdentity::production();
    $pattern = '/'.str_replace('/', '\/', $identity->identityRegexp).'/';

    expect($identity->cosignArguments())->toBe([
        '--certificate-identity-regexp', $identity->identityRegexp,
        '--certificate-oidc-issuer', 'https://token.actions.githubusercontent.com',
        '--certificate-github-workflow-trigger', 'push',
    ])
        ->and(preg_match($pattern, 'https://github.com/beyerenrico/fieldset/.github/workflows/release.yml@refs/tags/v2.0.0-alpha.1'))->toBe(1)
        ->and(preg_match($pattern, 'https://github.com/beyerenrico/fieldset/.github/workflows/release.yml@refs/heads/main'))->toBe(0)
        ->and(preg_match($pattern, 'https://github.com/beyerenrico/fieldset/.github/workflows/releasexyml@refs/tags/v2.0.0'))->toBe(0)
        ->and(preg_match($pattern, 'https://github.com/goreleaser/goreleaser/.github/workflows/release.yml@refs/tags/v2.18.3'))->toBe(0)
        ->and(preg_match($pattern, 'https://github.com/beyerenrico/form-schema-app/.github/workflows/release.yml@refs/tags/v2.1.0'))->toBe(0)
        ->and(fn () => new SignerIdentity('https://github.com/.*'))->toThrow(ReleaseException::class, 'anchored');
});

it('checks only the signature of any blob with verifySignature, without the schema', function (): void {
    $blob = $this->directory->path().'/checksums.txt';
    file_put_contents($blob, "abc  file\n");

    manifestVerifier($this->docker, TrustPolicy::keyless(SignerIdentity::production()))->verifySignature($blob, $this->bundle);
    $this->docker->queueOutcome('runContainer', new ProcessOutcome(1, '', 'Error: invalid signature'));

    expect($this->docker->called('runContainer')[0]['input'])->toBe("abc  file\n")
        ->and(fn () => manifestVerifier($this->docker, TrustPolicy::keyless(SignerIdentity::production()))->verifySignature($blob, $this->bundle))
        ->toThrow(fn (InstanceException $e) => expect($e->errorCode)->toBe('manifest_signature_invalid'));
});
