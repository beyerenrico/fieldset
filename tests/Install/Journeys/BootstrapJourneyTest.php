<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\CliDockerGateway;
use App\Domain\Instance\Docker\SymfonyCommandRunner;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Release\ManifestSchema;
use App\Domain\Instance\Release\ManifestVerifier;
use App\Domain\Instance\Release\SignerIdentity;
use App\Domain\Instance\Release\TrustPolicy;
use App\Domain\Instance\State\InstanceState;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\Cosign;
use Tests\Support\Docker\ReleaseHarness;
use Tests\Support\Docker\SharedHarness;
use Tests\Support\Docker\TestImage;

/*
 * U4 / M9-T07: install.sh and the shim against the local release harness with real Docker (R7, R8, R58, KTD2,
 * ADR 0070). The shared harness release (harness builds of the image and install.sh, SharedHarness) serves the
 * whole file. To observe the hand-over contract (argv, user, environment, mounts) a `docker` wrapper on PATH can
 * swap the ops container's `fieldset` entrypoint for tests/Install/Harness/ops-probe.php, which reports it from inside
 * the real container; FIELDSET_PROBE_OFF=1 lets the real fieldset commands run (U5: `fieldset install` exits 2 for a missing
 * flag without a TTY, `fieldset doctor` fails the nonce check). Full installs are J1 and J12.
 */

beforeAll(function (): void {
    if (TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        [$harness, $release] = SharedHarness::get();
        $GLOBALS['fieldsetBootstrap'] = ['harness' => $harness, 'release' => $release, 'work' => bootstrapWorkspace($harness->directory.'/bootstrap')];
    } catch (Throwable $exception) {
        $GLOBALS['fieldsetBootstrapError'] = $exception;
    }
});

afterAll(function (): void {
    // The shared harness (and the pulled app image with it) stays for the other journeys; SharedHarness stops it.
    unset($GLOBALS['fieldsetBootstrap'], $GLOBALS['fieldsetBootstrapError']);
});

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FIELDSET_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }

    if (isset($GLOBALS['fieldsetBootstrapError'])) {
        throw $GLOBALS['fieldsetBootstrapError'];
    }

    ['harness' => $this->harness, 'release' => $this->release, 'work' => $this->work] = $GLOBALS['fieldsetBootstrap'];
    file_put_contents($this->work.'/docker.log', '');
    $this->appReference = $this->harness->registry().'/beyerenrico/form-schema-app@'.$this->release->appDigest;
});

/** The docker wrapper, the probe and a log; returns the workspace path. */
function bootstrapWorkspace(string $work): string
{
    mkdir($work.'/bin', 0755, true);
    mkdir($work.'/probe', 0755);
    copy(TestImage::repository().'/tests/Install/Harness/ops-probe.php', $work.'/probe/ops-probe.php');
    file_put_contents($work.'/probe/probe.sh', "#!/bin/sh\nexec php /fieldset-probe/ops-probe.php \"\$@\"\n");
    file_put_contents($work.'/bin/docker', <<<'SH'
        #!/bin/sh
        { for a in "$@"; do printf '%s\037' "$a"; done; printf '\036\n'; } >> "$FIELDSET_DOCKER_LOG"
        if [ "${1:-}" = run ]; then
            n=$# prev=''
            while [ "$n" -gt 0 ]; do
                a=$1
                shift
                n=$((n - 1))
                if [ "$prev" = --entrypoint ] && [ "$a" = fieldset ]; then
                    if [ -n "${FIELDSET_PROBE_OFF:-}" ]; then set -- "$@" fieldset; else set -- "$@" /fieldset-probe/probe.sh -v "$FIELDSET_PROBE_DIR:/fieldset-probe:ro"; fi
                    [ -z "${FIELDSET_PROBE_HIDE_NONCE:-}" ] || rm -f "$FIELDSET_PROBE_HIDE_NONCE"/.fieldset-nonce-*
                elif [ -n "${FIELDSET_PROBE_DROP_MOUNT:-}" ] && [ "$a" = "$FIELDSET_PROBE_DROP_MOUNT:$FIELDSET_PROBE_DROP_MOUNT" ]; then
                    set -- "$@" "$FIELDSET_PROBE_DIR:/fieldset-probe-dropped:ro"
                else
                    set -- "$@" "$a"
                fi
                prev=$a
            done
        fi
        exec "$FIELDSET_REAL_DOCKER" "$@"
        SH);
    chmod($work.'/bin/docker', 0755);
    chmod($work.'/probe/probe.sh', 0755);
    chmod($work.'/probe/ops-probe.php', 0644);

    return $work;
}

/**
 * @param  list<string>  $command
 * @param  array<string, string|false>  $env
 */
function bootstrapRun(object $test, array $command, array $env = [], ?string $cwd = null): Process
{
    $process = new Process($command, $cwd ?? $test->work, [
        'PATH' => $test->work.'/bin:'.getenv('PATH'),
        'FIELDSET_DOCKER_LOG' => $test->work.'/docker.log',
        'FIELDSET_REAL_DOCKER' => trim((string) shell_exec('command -v docker')),
        'FIELDSET_PROBE_DIR' => $test->work.'/probe',
        'FIELDSET_RELEASE_URL' => $test->harness->releaseUrl(),
        'FIELDSET_REGISTRY' => $test->harness->registry(),
        'FIELDSET_TRUST_KEY' => $test->harness->cosign->publicKey(),
        ...$env,
    ]);
    $process->setTimeout(900);
    $process->run();

    return $process;
}

/** @return list<list<string>> every docker call the wrapper saw */
function bootstrapDockerCalls(object $test): array
{
    $records = array_filter(explode("\x1e\n", (string) file_get_contents($test->work.'/docker.log')));

    return array_values(array_map(static fn (string $record): array => explode("\x1f", rtrim($record, "\x1f")), $records));
}

/** @return array<string, mixed> */
function bootstrapProbe(Process $process): array
{
    $lines = array_values(array_filter(explode("\n", $process->getOutput()), static fn (string $line): bool => str_starts_with($line, '{"probe"')));

    return $lines === [] ? [] : (array) json_decode($lines[array_key_last($lines)], true);
}

/** Installs into a fresh directory through the harness install.sh; returns [process, directory]. */
function bootstrapInstall(object $test, string $name, array $arguments = [], array $env = []): array
{
    $directory = $test->work.'/'.$name;
    $process = bootstrapRun($test, ['/bin/sh', $test->release->directory.'/install.sh', '--dir', $directory, '--version', ReleaseHarness::version(), ...$arguments], $env);

    return [$process, $directory];
}

function bootstrapWriteState(object $test, string $directory): void
{
    $uid = posix_getuid();
    // The in-container owner UID (KTD2): 0 under Docker Desktop, where container root writes as the host user.
    $desktop = PHP_OS_FAMILY === 'Darwin' || str_contains((string) shell_exec('docker info --format "{{.OperatingSystem}}"'), 'Docker Desktop');
    $state = InstanceState::create('6a0f3c1e-2b4d-4e8f-9a1b-3c5d7e9f0a2b', $directory, $uid, posix_getgid(), $desktop ? 0 : $uid)
        ->withRelease(ReleaseHarness::version(), ['app' => $test->appReference]);
    file_put_contents($directory.'/state.json', $state->toJson());
}

it('(a) runs nothing from a truncated install.sh', function (): void {
    $script = (string) file_get_contents($this->release->directory.'/install.sh');
    $directory = $this->work.'/truncated';

    foreach ([intdiv(strlen($script), 2), strrpos(rtrim($script), "\n") + 3, strlen(rtrim($script)) - 1] as $cut) {
        file_put_contents($this->work.'/truncated.sh', substr($script, 0, $cut));
        bootstrapRun($this, ['/bin/sh', $this->work.'/truncated.sh', '--dir', $directory]);

        expect(bootstrapDockerCalls($this))->toBe([], "cut at {$cut}")
            ->and(file_exists($directory))->toBeFalse();
    }
});

it('(b) fails with exit 3 for a manifest signed by another key, before pulling anything but cosign', function (): void {
    $version = '2.0.0-alpha.2-test';
    $directory = $this->harness->distDirectory()."/download/v{$version}";
    mkdir($directory, 0755, true);
    copy($this->release->manifestPath(), $directory.'/release-manifest.json');
    Cosign::generate($this->work.'/other-key')->signBlob($directory.'/release-manifest.json');

    $process = bootstrapRun($this, ['/bin/sh', $this->release->directory.'/install.sh', '--dir', $this->work.'/other-key-instance', '--version', $version, '--json']);
    $pulls = array_map(fn (array $call): string => end($call), array_filter(bootstrapDockerCalls($this), fn (array $call): bool => $call[0] === 'pull'));

    expect($process->getExitCode())->toBe(3, $process->getErrorOutput())
        ->and(json_decode($process->getOutput(), true)['errors'][0]['code'])->toBe('manifest_signature_invalid')
        ->and(array_diff($pulls, [ManifestVerifier::COSIGN_IMAGE]))->toBe([])
        ->and(array_filter(bootstrapDockerCalls($this), fn (array $call): bool => $call[0] === 'run'))->toBe([])
        ->and(file_exists($this->work.'/other-key-instance'))->toBeFalse();
});

it('(b) fails with exit 3 for a tampered manifest even when SHA256SUMS matches it', function (): void {
    $version = '2.0.0-alpha.3-test';
    $directory = $this->harness->distDirectory()."/download/v{$version}";
    mkdir($directory, 0755, true);
    $manifest = $this->release->manifest();
    $manifest['images']['app']['digest'] = 'sha256:'.str_repeat('0', 64);
    file_put_contents($directory.'/release-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    copy($this->release->bundlePath(), $directory.'/release-manifest.json.sigstore.json');
    file_put_contents($directory.'/SHA256SUMS', hash_file('sha256', $directory.'/release-manifest.json')."  release-manifest.json\n");

    $process = bootstrapRun($this, ['/bin/sh', $this->release->directory.'/install.sh', '--dir', $this->work.'/tampered-instance', '--version', $version]);

    expect($process->getExitCode())->toBe(3)
        ->and($process->getErrorOutput())->toContain('signature does not match')
        ->and(array_filter(bootstrapDockerCalls($this), fn (array $call): bool => $call[0] === 'pull' && str_contains(end($call), 'form-schema-app')))->toBe([])
        ->and(file_exists($this->work.'/tampered-instance'))->toBeFalse();
});

it('(c) exits 2 for FIELDSET_TRUST_KEY with the production install.sh, before any Docker call', function (): void {
    $process = bootstrapRun($this, ['/bin/sh', TestImage::repository().'/distribution/install.sh', '--dir', $this->work.'/production', '--json']);

    expect($process->getExitCode())->toBe(2)
        ->and(json_decode($process->getOutput(), true)['errors'][0]['code'])->toBe('trust_key_not_allowed')
        ->and(bootstrapDockerCalls($this))->toBe([]);
});

it('(d) verifies, pulls by digest and hands over to the ops container with mounts, user, env and argv', function (): void {
    [$process, $directory] = bootstrapInstall($this, 'instance', ['--owner-email', 'owner@example.test', '--json']);
    $probe = bootstrapProbe($process)['probe'] ?? [];
    $calls = bootstrapDockerCalls($this);
    $order = array_map(fn (array $call): string => $call[0].(in_array($call[0], ['pull', 'run'], true) ? ' '.implode(' ', array_slice($call, -2)) : ''), $calls);
    $ops = array_values(array_filter($calls, fn (array $call): bool => $call[0] === 'run' && in_array('fieldset', $call, true)))[0];
    $desktop = PHP_OS_FAMILY === 'Darwin' || str_contains((string) shell_exec('docker info --format "{{.OperatingSystem}}"'), 'Docker Desktop');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and(array_search('create', $order, true))->toBeLessThan(array_search('pull -q '.$this->appReference, $order, true))
        ->and(array_filter($calls, fn (array $call): bool => $call[0] === 'create'))->not->toBeEmpty()
        ->and($ops)->not->toContain('-i')
        ->and($ops)->not->toContain('-it')
        ->and($probe['argv'])->toBe(['install', '--dir', $directory, '--version', ReleaseHarness::version(), '--owner-email', 'owner@example.test', '--json'])
        ->and($probe['cwd'])->toBe($directory)
        ->and($probe['docker_server'])->not->toBe('')
        ->and($probe['home_writable'])->toBeTrue()
        ->and($probe['instance'])->toBe(['visible' => true, 'marker' => true, 'shim' => true, 'manifest' => true, 'writable' => true])
        ->and($probe['env'])->toMatchArray([
            'HOME' => '/run/fieldset-home', 'DOCKER_CONFIG' => '/run/fieldset-home/.docker', 'DOCKER_HOST' => 'unix:///var/run/docker.sock',
            'FIELDSET_LAUNCHER' => 'install.sh', 'FIELDSET_TTY' => '0', 'FIELDSET_HOST_UID' => (string) posix_getuid(), 'FIELDSET_HOST_GID' => (string) posix_getgid(),
            'FIELDSET_INSTANCE_DIR' => $directory, 'FIELDSET_DOCKER_RUNTIME' => $desktop ? 'desktop' : 'rootful',
            'FIELDSET_CONTAINER_UID' => $desktop ? '0' : (string) posix_getuid(), 'FIELDSET_REGISTRY' => $this->harness->registry(),
            'FIELDSET_RELEASE_MANIFEST' => "{$directory}/releases/".ReleaseHarness::version().'/release-manifest.json',
            'FIELDSET_TRUST_KEY' => realpath($this->harness->cosign->publicKey()),
        ])
        ->and($probe['uid'])->toBe($desktop ? 0 : posix_getuid())
        ->and(substr(sprintf('%o', fileperms($directory)), -3))->toBe('700')
        ->and(file_get_contents($directory.'/fieldset'))->toBe(file_get_contents(TestImage::repository().'/distribution/shim.sh'))
        ->and(file_get_contents($directory.'/releases/'.ReleaseHarness::version().'/release-manifest.json'))->toBe(file_get_contents($this->release->manifestPath()))
        ->and(glob($directory.'/.fieldset-nonce-*'))->toBe([]);
});

it('(d) the real fieldset install exits 2 (input_required) without a terminal when a required flag is missing (R26)', function (): void {
    [$process, $directory] = bootstrapInstall($this, 'missing-flag', ['--owner-email', 'owner@example.test', '--json'], ['FIELDSET_PROBE_OFF' => '1']);
    $json = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(2, $process->getErrorOutput().$process->getOutput())
        ->and($json['command'])->toBe('install')
        ->and($json['errors'][0]['code'])->toBe('input_required')
        ->and($json['data']['missing'])->toBe(['--org'])
        ->and(file_exists($directory.'/state.json'))->toBeFalse()
        ->and(glob($directory.'/.fieldset-nonce-*'))->toBe([]);
});

it('(e) the shim runs the real fieldset doctor, which fails the nonce check with exit 3 when the daemon cannot see the directory', function (): void {
    [, $directory] = bootstrapInstall($this, 'shim-instance');
    bootstrapWriteState($this, $directory);

    $visible = bootstrapRun($this, [$directory.'/fieldset', 'doctor', '--json'], ['FIELDSET_PROBE_OFF' => '1']);
    $hidden = bootstrapRun($this, [$directory.'/fieldset', 'doctor', '--json'], ['FIELDSET_PROBE_OFF' => '1', 'FIELDSET_PROBE_HIDE_NONCE' => $directory]);
    $checks = array_column(json_decode($visible->getOutput(), true)['data']['checks'] ?? [], 'status', 'key');
    $error = json_decode($hidden->getOutput(), true)['errors'][0] ?? [];

    expect($visible->getExitCode())->toBe(0, $visible->getErrorOutput().$visible->getOutput())
        ->and($checks['path_sharing'] ?? null)->toBe('ok')
        ->and($checks['permissions'] ?? null)->toBe('ok')
        ->and($hidden->getExitCode())->toBe(3)
        ->and($error['code'] ?? null)->toBe('path_not_shared')
        ->and($error['hint'] ?? '')->toContain('File sharing');
});

it('(f) writes a --secrets-file in a host directory on the host, and refuses paths outside the mounts', function (): void {
    [, $directory] = bootstrapInstall($this, 'secrets-instance');
    bootstrapWriteState($this, $directory);
    $out = $this->work.'/host-out';
    mkdir($out, 0755);

    $written = bootstrapRun($this, [$directory.'/fieldset', 'login-link', 'owner@example.test', '--secrets-file', 'host-out/link.json', '--json']);
    $unshared = bootstrapRun($this, [$directory.'/fieldset', 'login-link', 'owner@example.test', '--secrets-file', $out.'/again.json', '--json'], ['FIELDSET_PROBE_DROP_MOUNT' => $out]);
    $outside = new Process([
        'docker', 'run', '--rm', '--user', posix_getuid().':'.posix_getgid(), '-v', "{$directory}:{$directory}", '-v', $this->work.'/probe:/fieldset-probe:ro',
        '-e', 'FIELDSET_NONCE=.fieldset-nonce-00000000000000000000000000000000', '-e', "FIELDSET_INSTANCE_DIR={$directory}", '-e', 'FIELDSET_MOUNTS=',
        '--entrypoint', '/fieldset-probe/probe.sh', $this->appReference, 'login-link', '--secrets-file', '/srv/elsewhere/link.json', '--dir', $directory,
    ]);
    touch($directory.'/.fieldset-nonce-00000000000000000000000000000000');
    $outside->run();

    expect($written->getExitCode())->toBe(0, $written->getErrorOutput().$written->getOutput())
        ->and(file_get_contents($out.'/link.json'))->toBe("{\"probe\":true}\n")
        ->and(substr(sprintf('%o', fileperms($out.'/link.json')), -3))->toBe('600')
        ->and(fileowner($out.'/link.json'))->toBe(posix_getuid())
        ->and(glob($out.'/.fieldset-nonce-*'))->toBe([])
        ->and($unshared->getExitCode())->toBe(3)
        ->and(bootstrapProbe($unshared)['error']['code'] ?? null)->toBe('path_not_shared')
        ->and(file_exists($out.'/again.json'))->toBeFalse()
        ->and($outside->getExitCode())->toBe(2, $outside->getErrorOutput())
        ->and(bootstrapProbe($outside)['error']['code'] ?? null)->toBe('path_not_mounted');
});

it('(g) the production verify path accepts a real GitHub Actions keyless bundle offline and rejects other workflows', function (): void {
    $fixtures = TestImage::repository().'/tests/Fixtures/Release/keyless';
    $verify = function (SignerIdentity $identity, string $blob = 'checksums.txt') use ($fixtures): ?string {
        $verifier = new ManifestVerifier(new CliDockerGateway(new SymfonyCommandRunner), TrustPolicy::keyless($identity, $fixtures.'/trusted_root.json'), new ManifestSchema(TestImage::repository().'/distribution/manifest.schema.json'));
        try {
            $verifier->verifySignature(str_starts_with($blob, '/') ? $blob : "{$fixtures}/{$blob}", $fixtures.'/checksums.txt.sigstore.json');

            return null;
        } catch (InstanceException $e) {
            return $e->errorCode.': '.$e->getMessage();
        }
    };
    $tampered = $this->work.'/checksums-tampered.txt';
    file_put_contents($tampered, file_get_contents($fixtures.'/checksums.txt')."0000  extra\n");

    expect($verify(SignerIdentity::githubWorkflow('goreleaser/goreleaser', 'release.yml')))->toBeNull()
        ->and($verify(SignerIdentity::production()))->toStartWith('manifest_signature_invalid')
        ->and($verify(SignerIdentity::production()))->toContain('no matching CertificateIdentity')
        ->and($verify(SignerIdentity::githubWorkflow('goreleaser/goreleaser', 'ci.yml')))->toStartWith('manifest_signature_invalid')
        ->and($verify(SignerIdentity::githubWorkflow('goreleaser/goreleaser', 'release.yml'), $tampered))->toStartWith('manifest_signature_invalid');
});
