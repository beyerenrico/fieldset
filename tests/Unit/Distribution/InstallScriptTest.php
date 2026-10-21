<?php

declare(strict_types=1);

use App\Domain\Instance\Console\LaunchGuard;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Release\ManifestSchema;
use App\Domain\Instance\Release\ManifestVerifier;
use App\Domain\Instance\Release\SignerIdentity;
use App\Domain\Instance\Release\TrustPolicy;
use Tests\Support\Docker\DistributionScripts;
use Tests\Support\Docker\InstallScriptBuild;
use Tests\Support\Instance\RecordingDockerGateway;
use Tests\Support\Release\ReleaseFixtures;

/*
 * U4 / M9-T07: distribution/install.sh under dash with recording `docker` and `curl` stubs (R7, R8, R58, KTD2,
 * ADR 0070). The real-Docker side runs in tests/Install/Journeys/BootstrapJourneyTest.php.
 */

beforeEach(function (): void {
    $this->scripts = new DistributionScripts;
    $this->release = $this->scripts->root.'/release';
    mkdir($this->release);
    mkdir($this->scripts->root.'/home');
    [$manifest] = ReleaseFixtures::writeSignedManifest($this->release);
    rename($this->release.'/'.ManifestVerifier::BUNDLE, $this->release.'/release-manifest.json.sigstore.json');
    $this->manifestJson = (string) file_get_contents($manifest);
    $this->dir = $this->scripts->root.'/instance';
    $this->key = $this->scripts->root.'/cosign.pub';
    file_put_contents($this->key, "-----BEGIN PUBLIC KEY-----\n");
    $this->harness = InstallScriptBuild::writeHarness($this->scripts->root.'/install-harness.sh');
    $this->production = DistributionScripts::repository().'/distribution/install.sh';
    $this->env = ['STUB_RELEASE_DIR' => $this->release, 'STUB_UNAME' => 'Linux', 'STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|host|[]'];
});

afterEach(fn () => $this->scripts->cleanUp());

/** @return array<string, string> the single-quoted assignments of install.sh's trust function */
function installTrustLiterals(): array
{
    preg_match_all("/^\t(cosign_image|identity_regexp|oidc_issuer|workflow_trigger)='([^']*)'$/m", (string) file_get_contents(base_path('distribution/install.sh')), $matches, PREG_SET_ORDER);

    return array_column($matches, 2, 1);
}

it('executes nothing when the download is truncated anywhere (function-wrapped)', function (string $shell): void {
    $script = (string) file_get_contents($this->production);
    $cuts = [];
    for ($offset = strpos($script, "\n"); $offset !== false; $offset = strpos($script, "\n", $offset + 1)) {
        $cuts[] = $offset + 1;
    }
    $last = strrpos(rtrim($script), "\n") + 1;
    $cuts = array_unique([...array_slice($cuts, 0, -1), ...range($last, strlen(rtrim($script)) - 1)]);
    $scripts = new DistributionScripts($shell);

    try {
        foreach ($cuts as $cut) {
            file_put_contents($scripts->root.'/truncated.sh', substr($script, 0, $cut));
            $scripts->run($scripts->root.'/truncated.sh', ['--dir', $this->dir, '--owner-email', 'a@b.test'], $this->env);

            expect($scripts->calls())->toBe([], "cut at byte {$cut}")
                ->and(file_exists($this->dir))->toBeFalse("cut at byte {$cut}");
        }
    } finally {
        $scripts->cleanUp();
    }

    expect(count($cuts))->toBeGreaterThan(150);
})->with(['dash' => '/bin/dash', 'sh' => '/bin/sh']);

it('keeps the trust literals identical to the image and the release workflow', function (): void {
    $literals = installTrustLiterals();

    expect($literals)->toBe([
        'cosign_image' => ManifestVerifier::COSIGN_IMAGE,
        'identity_regexp' => SignerIdentity::production()->identityRegexp,
        'oidc_issuer' => SignerIdentity::GITHUB_ACTIONS_ISSUER,
        'workflow_trigger' => SignerIdentity::WORKFLOW_TRIGGER,
    ])
        ->and((string) file_get_contents(base_path('.github/workflows/release.yml')))
        ->toContain("CERTIFICATE_IDENTITY_REGEXP: '".SignerIdentity::production()->identityRegexp."'");
});

it('refuses FIELDSET_TRUST_KEY in the production build with exit 2 before any download or Docker call', function (): void {
    $process = $this->scripts->run($this->production, ['--dir', $this->dir, '--json'], [...$this->env, 'FIELDSET_TRUST_KEY' => $this->key]);
    $json = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('FIELDSET_TRUST_KEY is accepted only by release-harness builds')
        ->and($json['status'])->toBe('failed')
        ->and($json['command'])->toBe('install')
        ->and($json['errors'][0]['code'])->toBe('trust_key_not_allowed')
        ->and($this->scripts->calls())->toBe([])
        ->and(file_exists($this->dir))->toBeFalse();
});

it('allows plain-http release URLs only in the harness build', function (): void {
    $http = ['FIELDSET_RELEASE_URL' => 'http://127.0.0.1:1234', 'STUB_DOCKER_DOWN' => '1'];
    $production = $this->scripts->run($this->production, ['--dir', $this->dir], [...$this->env, ...$http]);
    $harness = $this->scripts->run($this->harness, ['--dir', $this->dir], [...$this->env, ...$http]);

    expect($production->getExitCode())->toBe(2)
        ->and($production->getErrorOutput())->toContain('FIELDSET_RELEASE_URL must be an https URL')
        ->and($harness->getExitCode())->toBe(10)
        ->and($this->scripts->run($this->production, ['--dir', $this->dir], [...$this->env, 'FIELDSET_RELEASE_URL' => 'ftp://x'])->getExitCode())->toBe(2);
});

it('verifies the manifest with the pinned cosign image and the built-in identity before pulling anything else', function (): void {
    $process = $this->scripts->run($this->production, ['--dir', $this->dir, '--version', '2.0.0-alpha.1'], $this->env);
    $docker = $this->scripts->calls('docker');
    $create = array_values(array_filter($docker, fn (array $call): bool => $call[1] === 'create'))[0];
    $verifier = new ManifestVerifier(new RecordingDockerGateway, TrustPolicy::keyless(SignerIdentity::production()), ManifestSchema::bundled());

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_map(fn (array $call): string => $call[1].($call[1] === 'pull' ? ' '.$call[3] : ''), array_slice($docker, 0, 8)))->toBe([
            'version', 'image', 'pull '.ManifestVerifier::COSIGN_IMAGE, 'create', 'cp', 'start', 'rm',
            'pull ghcr.io/beyerenrico/form-schema-app@'.ReleaseFixtures::APP_DIGEST,
        ])
        ->and(array_slice($create, 2))->toBe(['-e', 'HOME=/tmp', ManifestVerifier::COSIGN_IMAGE, ...array_slice($verifier->cosignArguments(), 0, -1), '/verify/release-manifest.json'])
        ->and(file_get_contents($this->scripts->root.'/verified/release-manifest.json'))->toBe($this->manifestJson)
        ->and(substr(sprintf('%o', fileperms($this->scripts->root.'/verified/bundle.json')), -3))->toBe('644')
        ->and($this->scripts->calls('curl')[0])->toContain('--proto', '=https', '--tlsv1.2', 'https://github.com/beyerenrico/form-schema-app/releases/download/v2.0.0-alpha.1/release-manifest.json');
});

it('fails with exit 3 when the signature does not verify, before any other pull and without an instance directory', function (): void {
    $process = $this->scripts->run($this->harness, ['--dir', $this->dir, '--json'], [...$this->env, 'FIELDSET_TRUST_KEY' => $this->key, 'STUB_VERIFY_EXIT' => '1']);
    $pulls = array_map(fn (array $call): string => end($call), array_filter($this->scripts->calls('docker'), fn (array $call): bool => $call[1] === 'pull'));

    expect($process->getExitCode())->toBe(3)
        ->and(json_decode($process->getOutput(), true)['errors'][0]['code'])->toBe('manifest_signature_invalid')
        ->and(json_decode($process->getOutput(), true)['errors'][0]['message'])->toContain('invalid signature')
        ->and(array_values($pulls))->toBe([ManifestVerifier::COSIGN_IMAGE])
        ->and($this->scripts->opsRuns())->toBe([])
        ->and(file_exists($this->dir))->toBeFalse()
        ->and(glob($this->scripts->root.'/fieldset-install.*'))->toBe([]);
});

it('gives the same stop-and-report hint for a bad signature as fieldset and the agent guide (R51)', function (): void {
    $process = $this->scripts->run($this->harness, ['--dir', $this->dir, '--json'], [...$this->env, 'FIELDSET_TRUST_KEY' => $this->key, 'STUB_VERIFY_EXIT' => '1']);

    expect(json_decode($process->getOutput(), true)['errors'][0])->toMatchArray(['hint' => ManifestVerifier::SIGNATURE_HINT, 'retryable' => false]);
});

it('verifies with the local key and no network in the harness build', function (): void {
    $process = $this->scripts->run($this->harness, ['--dir', $this->dir], [...$this->env, 'FIELDSET_TRUST_KEY' => $this->key, 'STUB_IMAGE_PRESENT' => '1']);
    $create = array_values(array_filter($this->scripts->calls('docker'), fn (array $call): bool => $call[1] === 'create'))[0];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_slice($create, 2))->toBe(['-e', 'HOME=/tmp', '--network', 'none', ManifestVerifier::COSIGN_IMAGE, 'verify-blob', '--bundle', '/verify/bundle.json', '--key', '/verify/trust.pub', '--insecure-ignore-tlog=true', '/verify/release-manifest.json'])
        ->and(file_get_contents($this->scripts->root.'/verified/trust.pub'))->toBe("-----BEGIN PUBLIC KEY-----\n")
        ->and(array_filter($this->scripts->calls('docker'), fn (array $call): bool => $call[1] === 'pull' && end($call) === ManifestVerifier::COSIGN_IMAGE))->toBe([])
        ->and($this->scripts->calls('curl')[0])->toContain('https://github.com/beyerenrico/form-schema-app/releases/latest/download/release-manifest.json');
});

it('creates the instance directory, stores the verified release and installs the shim from the image', function (): void {
    $process = $this->scripts->run($this->production, ['--dir', $this->dir.'//', '--json'], [...$this->env, 'FIELDSET_REGISTRY' => '127.0.0.1:5000']);
    $pulled = '127.0.0.1:5000/beyerenrico/form-schema-app@'.ReleaseFixtures::APP_DIGEST;
    $cat = array_values(array_filter($this->scripts->calls('docker'), fn (array $call): bool => in_array('cat', $call, true)))[0];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe('')
        ->and(collect(explode("\n", trim($process->getErrorOutput())))->map(fn (string $line): array => json_decode($line, true) ?? [])->pluck('phase')->all())
        ->toBe(['verify', 'pull', 'handover'])
        ->and(substr(sprintf('%o', fileperms($this->dir)), -3))->toBe('700')
        ->and(file_get_contents($this->dir.'/.fieldset-instance'))->toBe("{\"schema_version\":\"1.0\",\"kind\":\"fieldset instance\"}\n")
        ->and(file_get_contents($this->dir.'/releases/2.0.0-alpha.1/release-manifest.json'))->toBe($this->manifestJson)
        ->and(is_file($this->dir.'/releases/2.0.0-alpha.1/release-manifest.json.sigstore.json'))->toBeTrue()
        ->and(file_get_contents($this->dir.'/fieldset'))->toBe(file_get_contents(base_path('distribution/shim.sh')))
        ->and(substr(sprintf('%o', fileperms($this->dir.'/fieldset')), -3))->toBe('700')
        ->and(array_slice($cat, 1))->toBe(['run', '--rm', '--network', 'none', '--entrypoint', 'cat', $pulled, '/var/www/html/distribution/shim.sh'])
        ->and($this->scripts->opsRuns()[0])->toContain($pulled);
});

it('hands over to fieldset install in an ops container with the host facts, mounts and user (KTD2)', function (): void {
    $hook = $this->scripts->writeExecutable('ops-hook', "#!/bin/sh\n[ -f \"\$FIELDSET_INSTANCE_DIR/\$FIELDSET_NONCE\" ] && touch \"\$STUB_ROOT/nonce-seen\"\n");
    $process = $this->scripts->run($this->production, ['--dir', $this->dir, '--owner-email', 'owner@example.test', '--json', '--yes'], [...$this->env, 'STUB_OPS_HOOK' => $hook, 'STUB_OPS_EXIT' => '6']);
    $run = $this->scripts->opsRuns()[0];
    $env = $this->scripts->opsEnvironment();
    $image = 'ghcr.io/beyerenrico/form-schema-app@'.ReleaseFixtures::APP_DIGEST;
    $uid = trim((string) shell_exec('id -u'));
    $gid = trim((string) shell_exec('id -g'));

    expect($process->getExitCode())->toBe(6)
        ->and(array_slice($run, 0, 20))->toBe([
            'run', '--rm', '--init', '--label', 'app.fieldset.role=ops', '--user', "{$uid}:{$gid}", '--tmpfs', "/run/fieldset-home:mode=0700,uid={$uid},gid={$gid}",
            '-e', 'HOME=/run/fieldset-home', '-e', 'DOCKER_CONFIG=/run/fieldset-home/.docker',
            '-e', 'DOCKER_HOST=unix:///var/run/docker.sock', '-v', '/var/run/docker.sock:/var/run/docker.sock', '-v', "{$this->dir}:{$this->dir}", '-w',
        ])
        ->and(array_slice($run, array_search('--entrypoint', $run, true)))->toBe([
            '--entrypoint', 'fieldset', $image, 'install', '--dir', $this->dir, '--owner-email', 'owner@example.test', '--json', '--yes',
        ])
        ->and($run)->not->toContain('-i')
        ->and($run)->not->toContain('-it')
        ->and($env)->toMatchArray([
            'FIELDSET_LAUNCHER' => 'install.sh', 'FIELDSET_TTY' => '0', 'FIELDSET_HOST_UID' => $uid, 'FIELDSET_HOST_GID' => $gid, 'FIELDSET_HOST_OS' => 'linux',
            'FIELDSET_INSTANCE_DIR' => $this->dir, 'FIELDSET_DESKTOP' => '0', 'FIELDSET_TZ' => 'Europe/Berlin', 'FIELDSET_DOCKER_RUNTIME' => 'rootful',
            'FIELDSET_CONTAINER_UID' => $uid, 'FIELDSET_DOCKER_SOCKET' => '/var/run/docker.sock', 'FIELDSET_MOUNTS' => '', 'FIELDSET_OPEN_URL_FILE' => '',
            'FIELDSET_RELEASE_MANIFEST' => $this->dir.'/releases/2.0.0-alpha.1/release-manifest.json',
        ])
        ->and($env['FIELDSET_NONCE'])->toMatch('/^\.fieldset-nonce-[0-9a-f]{32}$/')
        ->and(is_file($this->scripts->root.'/nonce-seen'))->toBeTrue()
        ->and(glob($this->dir.'/.fieldset-nonce-*'))->toBe([]);

    foreach (['FIELDSET_LAUNCHER', 'FIELDSET_TTY', 'FIELDSET_HOST_UID', 'FIELDSET_NONCE', 'FIELDSET_MOUNTS', 'FIELDSET_TRUST_KEY', 'FIELDSET_REGISTRY', 'FIELDSET_RELEASE_MANIFEST'] as $name) {
        expect(implode(' ', $run))->toContain("-e {$name} ");
    }
});

it('keeps DOCKER_DEFAULT_PLATFORM for its docker calls but cosign\'s and hands it to the ops container (ADR 0070)', function (?string $platform): void {
    $process = $this->scripts->run($this->production, ['--dir', $this->dir, '--json'], [...$this->env, 'DOCKER_DEFAULT_PLATFORM' => $platform ?? false]);
    $p = $platform ?? '';
    $events = collect(explode("\n", trim($process->getErrorOutput())))->map(fn (string $line): array => json_decode($line, true) ?? []);

    // version, inspect, pull + create cosign (the engine's platform), cp, start, rm, pull app, cat shim, docker info, ops container
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_map(fn (array $call): string => $call[0].'='.$call[1], $this->scripts->dockerPlatforms()))
        ->toBe(["version={$p}", "image={$p}", 'pull=', 'create=', "cp={$p}", "start={$p}", "rm={$p}", "pull={$p}", "run={$p}", "info={$p}", "run={$p}"])
        ->and(implode(' ', $this->scripts->opsRuns()[0]))->toContain('-e DOCKER_DEFAULT_PLATFORM ')
        ->and($this->scripts->opsEnvironment()['DOCKER_DEFAULT_PLATFORM'] ?? null)->toBe($platform)
        ->and($events->firstWhere('phase', 'pull')['message'])->toEndWith('@'.ReleaseFixtures::APP_DIGEST.($platform === null ? '' : " for {$platform}"));
})->with(['unset' => null, 'amd64 (emulated on Apple Silicon)' => 'linux/amd64', 'arm64' => 'linux/arm64', 'with a variant' => 'linux/arm64/v8']);

it('refuses a DOCKER_DEFAULT_PLATFORM no release ships with exit 2 invalid_platform before any Docker call', function (string $platform): void {
    $process = $this->scripts->run($this->production, ['--dir', $this->dir, '--json'], [...$this->env, 'DOCKER_DEFAULT_PLATFORM' => $platform]);
    $error = json_decode($process->getOutput(), true)['errors'][0] ?? [];

    expect($process->getExitCode())->toBe(2, $process->getErrorOutput())
        ->and($error['code'] ?? null)->toBe('invalid_platform')
        ->and($error['hint'])->toBe("Unset DOCKER_DEFAULT_PLATFORM to use the engine's own platform.")
        ->and($this->scripts->calls())->toBe([])
        ->and(file_exists($this->dir))->toBeFalse();
})->with(['linux/s390x', 'amd64', 'linux/arm/v7', 'linux/amd64,linux/arm64', 'windows/amd64', 'linux/arm64/v8 --privileged', "linux/amd64\nlinux/s390x"]);

it('accepts the same DOCKER_DEFAULT_PLATFORM values in install.sh, the shim and HostFacts', function (): void {
    $values = ['linux/amd64', 'linux/arm64', 'linux/arm64/v8', 'linux/amd64/v2', 'linux/s390x', 'linux/arm/v7', 'amd64', 'linux/amd64/',
        'linux/amd64/v10', 'linux/amd64,linux/arm64', 'Linux/AMD64', 'linux/arm64/v8 x', "linux/amd64\nlinux/arm64"];
    $snippet = 'for p in '.implode(' ', array_map(escapeshellarg(...), $values)).'; do %s "$p" && printf 1 || printf 0; done';

    $install = $this->scripts->source(base_path('distribution/install.sh'), sprintf($snippet, 'fieldset_install_platform_ok'))->getOutput();
    $shim = $this->scripts->source(base_path('distribution/shim.sh'), sprintf($snippet, 'fieldset_platform_ok'))->getOutput();
    $php = implode('', array_map(fn (string $value): string => HostFacts::fromEnvironment(['DOCKER_DEFAULT_PLATFORM' => $value])->platform === null ? '0' : '1', $values));

    expect($php)->toBe('1111000000000')
        ->and($install)->toBe($php)
        ->and($shim)->toBe($php);
});

it('resolves the release and the image from the manifest, honouring FIELDSET_REGISTRY', function (array $env, string $version, int $status, string $expected): void {
    $process = $this->scripts->source(base_path('distribution/install.sh'), 'json=0; version="'.$version.'"; fieldset_install_read_manifest "'.$this->release.'/release-manifest.json" && printf "%s %s" "$release" "$ref"', $env);

    expect($process->getExitCode())->toBe($status)
        ->and($status === 0 ? $process->getOutput() : $process->getErrorOutput())->toContain($expected);
})->with([
    'canonical registry' => [[], '', 0, '2.0.0-alpha.1 ghcr.io/beyerenrico/form-schema-app@'.ReleaseFixtures::APP_DIGEST],
    'mirror' => [['FIELDSET_REGISTRY' => 'mirror.example:5000'], '2.0.0-alpha.1', 0, 'mirror.example:5000/beyerenrico/form-schema-app@'],
    'other version' => [[], '2.0.0', 3, 'The manifest is for 2.0.0-alpha.1, not 2.0.0'],
]);

it('names a manifest of another version release_mismatch, like fieldset', function (): void {
    $process = $this->scripts->source(base_path('distribution/install.sh'), 'json=1; version=2.0.0; fieldset_install_read_manifest "'.$this->release.'/release-manifest.json"');

    expect($process->getExitCode())->toBe(3)
        ->and(json_decode($process->getOutput(), true)['errors'][0])->toMatchArray(['code' => 'release_mismatch', 'retryable' => false]);
});

it('refuses a manifest in a format it cannot read with exit 3', function (): void {
    file_put_contents($this->release.'/release-manifest.json', '{"schema_version": 2, "version": "3.0.0"}');

    $process = $this->scripts->source(base_path('distribution/install.sh'), 'json=0; version=""; fieldset_install_read_manifest "'.$this->release.'/release-manifest.json"');

    expect($process->getExitCode())->toBe(3)
        ->and($process->getErrorOutput())->toContain('not in a format this install.sh reads');
});

it('validates flags and the instance directory with exit 2 before touching Docker', function (array $arguments, array $env, string $message, ?Closure $setup = null): void {
    if ($setup instanceof Closure) {
        $setup($this->dir);
    }

    $process = $this->scripts->run($this->production, $arguments === [] ? [] : str_replace('{dir}', $this->dir, $arguments), [...$this->env, ...$env]);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain($message)
        ->and($this->scripts->calls('docker'))->toBe([]);
})->with([
    'bad version' => [['--version', '2.0', '--dir', '{dir}'], [], '--version must look like'],
    'version with a newline' => [['--version', "2.0.0\nx", '--dir', '{dir}'], [], '--version must look like'],
    'bad registry' => [['--dir', '{dir}'], ['FIELDSET_REGISTRY' => 'https://evil'], 'FIELDSET_REGISTRY must be a registry host'],
    'bad platform' => [['--dir', '{dir}'], ['DOCKER_DEFAULT_PLATFORM' => 'linux/s390x'], 'DOCKER_DEFAULT_PLATFORM must be linux/amd64 or linux/arm64'],
    'dir with a colon' => [['--dir', '/tmp/a:b'], [], 'Cannot use /tmp/a:b'],
    'dir in the shared /tmp' => [['--dir', '/tmp/fieldset'], [], 'Cannot use /tmp/fieldset as the instance directory: fieldset mounts it'],
    'dir over the app code' => [['--dir', '/var/www/fieldset'], [], 'Cannot use /var/www/fieldset as the instance directory: fieldset mounts it'],
    'dir over image binaries' => [['--dir', '/usr/local/bin/fieldset'], [], 'Cannot use /usr/local/bin/fieldset as the instance directory'],
    'dir that only looks elsewhere' => [['--dir', '/home/u/../../tmp/./fieldset'], [], 'Cannot use /tmp/fieldset as the instance directory'],
    '/home itself' => [['--dir', '/home'], [], 'Cannot use /home as the instance directory'],
    'root dir' => [['--dir', '/'], [], 'Cannot use / as the instance directory'],
    'missing value' => [['--dir'], [], '--dir needs a value'],
    'symlink' => [['--dir', '{dir}'], [], 'is a symbolic link', function (string $dir): void {
        mkdir($dir.'-real');
        symlink($dir.'-real', $dir);
    }],
    'foreign directory' => [['--dir', '{dir}'], [], 'exists and is not an instance directory', function (string $dir): void {
        mkdir($dir);
        touch($dir.'/notes.txt');
    }],
    'file' => [['--dir', '{dir}'], [], 'exists and is not an instance directory', touch(...)],
]);

it('accepts an existing instance, a directory holding only backups/, and resolves the default and relative paths', function (): void {
    mkdir($this->dir.'/backups', 0700, true);
    $backupsOnly = $this->scripts->run($this->production, ['--dir', $this->dir], $this->env);
    $default = $this->scripts->source(base_path('distribution/install.sh'), 'nl="\n"; json=0; version=""; dir=""; build=production; fieldset_install_check_inputs; printf %s "$dir"', $this->env);
    $relative = $this->scripts->source(base_path('distribution/install.sh'), 'nl="\n"; json=0; version=""; dir="sub/inst///"; build=production; fieldset_install_check_inputs; printf %s "$dir"', $this->env);

    expect($backupsOnly->getExitCode())->toBe(0, $backupsOnly->getErrorOutput())
        ->and($this->scripts->run($this->production, ['--dir', $this->dir], $this->env)->getExitCode())->toBe(0)
        ->and($default->getOutput())->toBe(posix_getuid() === 0 ? '/opt/fieldset' : $this->scripts->root.'/home/fieldset')
        ->and($relative->getOutput())->toBe(realpath($this->scripts->root).'/sub/inst');
});

it('exits 10 when the Docker daemon is down and 3 when the release cannot be downloaded', function (): void {
    $down = $this->scripts->run($this->production, ['--dir', $this->dir, '--json'], [...$this->env, 'STUB_DOCKER_DOWN' => '1']);
    unlink($this->release.'/release-manifest.json.sigstore.json');
    $missing = $this->scripts->run($this->production, ['--dir', $this->dir, '--version', '2.0.0-alpha.1'], $this->env);

    expect($down->getExitCode())->toBe(10)
        ->and(json_decode($down->getOutput(), true)['errors'][0])->toMatchArray(['code' => 'docker_unavailable', 'retryable' => true])
        ->and($missing->getExitCode())->toBe(3)
        ->and($missing->getErrorOutput())->toContain('Cannot download https://github.com/beyerenrico/form-schema-app/releases/download/v2.0.0-alpha.1/release-manifest.json.sigstore.json')
        ->and(file_exists($this->dir))->toBeFalse();
});

it('tells a release that does not exist (exit 2) from a network failure (exit 3, retryable) and a missing bundle', function (array $arguments, array $env, ?string $remove, int $exit, string $code, bool $retryable, string $hint): void {
    if ($remove !== null) {
        unlink($this->release.'/'.$remove);
    }

    $process = $this->scripts->run($this->production, ['--dir', $this->dir, '--json', ...$arguments], [...$this->env, ...$env]);
    $error = json_decode($process->getOutput(), true)['errors'][0];

    expect($process->getExitCode())->toBe($exit, $process->getErrorOutput())
        ->and($error)->toMatchArray(['code' => $code, 'retryable' => $retryable])
        ->and($error['hint'])->toContain($hint)
        ->and($this->scripts->calls('curl')[0])->toContain('-w', '%{http_code}')
        ->and(file_exists($this->dir))->toBeFalse();
})->with([
    'no such --version' => [['--version', '2.0.9'], [], 'release-manifest.json', 2, 'release_not_found', false, 'Check --version'],
    'no latest release yet' => [[], [], 'release-manifest.json', 2, 'release_not_found', false, 'Before 2.0.0 there is no latest release'],
    'network down' => [['--version', '2.0.0-alpha.1'], ['STUB_CURL_EXIT' => '7'], null, 3, 'release_unavailable', true, 'Check the network'],
    'release without a signature bundle' => [['--version', '2.0.0-alpha.1'], [], 'release-manifest.json.sigstore.json', 3, 'manifest_missing', false, 'incomplete'],
]);

it('reports pull failures as retryable, with Docker\'s message inside NDJSON events only (--json)', function (array $env, string $code): void {
    $process = $this->scripts->run($this->production, ['--dir', $this->dir, '--json'], [...$this->env, ...$env]);
    $error = json_decode($process->getOutput(), true)['errors'][0];
    $events = array_map(fn (string $line): mixed => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", trim($process->getErrorOutput())));

    expect($process->getExitCode())->toBe(3)
        ->and($error)->toMatchArray(['code' => $code, 'retryable' => true])
        ->and($error['message'])->toContain('toomanyrequests: rate limit')
        ->and($events)->each->toHaveKeys(['ts', 'phase', 'level', 'message'])
        ->and(end($events))->toMatchArray(['level' => 'error'])
        ->and(end($events)['message'])->toContain('toomanyrequests: rate limit');
})->with([
    'app image' => [['STUB_IMAGE_PRESENT' => '1', 'STUB_PULL_EXIT' => '1', 'STUB_PULL_STDERR' => 'Error response from daemon: toomanyrequests: rate limit'], 'image_unavailable'],
    'cosign image' => [['STUB_PULL_EXIT' => '1', 'STUB_PULL_STDERR' => 'Error response from daemon: toomanyrequests: rate limit'], 'cosign_unavailable'],
]);

it('prints human lines without --json', function (): void {
    $process = $this->scripts->run($this->production, ['--dir', $this->dir], [...$this->env, 'STUB_DOCKER_DOWN' => '1']);

    expect($process->getExitCode())->toBe(10)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toBe("install.sh: The Docker daemon is not reachable.\n  Fix: Start Docker (or check your docker group membership), then run install.sh again.\n");
});

it('uses the same reserved-path rule as the shim and LaunchGuard, and allows its own defaults', function (): void {
    $paths = ['/', '/home', '/home/u/fieldset', '/tmp', '/tmp/fieldset', '/var', '/var/www/html', '/var/lib/fieldset', '/usr/local/bin', '/etc/ssl', '/run/user/1000',
        '/proc/1', '/sys/fs', '/dev/shm', '/bin', '/sbin/x', '/lib/x', '/lib64', '/root', '/root/fieldset', '/opt/fieldset', '/srv/fieldset', '/Users/u/fieldset',
        '/tmpfoo', '/variant', '/homes/u', '/mnt/data/fieldset'];
    $snippet = 'for p in '.implode(' ', $paths).'; do %s "$p" && printf 1 || printf 0; done';

    $install = $this->scripts->source(base_path('distribution/install.sh'), sprintf($snippet, 'fieldset_install_reserved'))->getOutput();
    $shim = $this->scripts->source(base_path('distribution/shim.sh'), sprintf($snippet, 'fieldset_reserved_path'))->getOutput();
    $php = implode('', array_map(fn (string $path): string => LaunchGuard::reserved($path) ? '1' : '0', $paths));
    preg_match('/^\tfor root in ([^;]+); do$/m', (string) file_get_contents(base_path('distribution/install.sh')), $installList);
    preg_match('/^\tfor fieldset_root in ([^;]+); do$/m', (string) file_get_contents(base_path('distribution/shim.sh')), $shimList);

    expect($install)->toBe($php)
        ->and($shim)->toBe($php)
        ->and($php)->toBe('110111111111111111110000000')
        ->and(explode(' ', $installList[1]))->toBe(LaunchGuard::RESERVED)
        ->and(explode(' ', $shimList[1]))->toBe(LaunchGuard::RESERVED);
});

it('passes --yes on to fieldset install and never asks about the PATH link with --yes, --json or without a terminal', function (string $link, string $yes, string $json, string $tty, bool $ask): void {
    $process = $this->scripts->source(base_path('distribution/install.sh'), "fieldset_install_wants_prompt '{$link}' {$yes} {$json} {$tty} && echo ask || echo quiet");

    expect(trim($process->getOutput()))->toBe($ask ? 'ask' : 'quiet');
})->with([
    'person at a terminal' => ['', '0', '0', '1', true],
    '--yes' => ['', '1', '0', '1', false],
    '--json' => ['', '0', '1', '1', false],
    'no terminal' => ['', '0', '0', '0', false],
    '--link given' => ['1', '0', '0', '1', false],
    '--no-link given' => ['0', '0', '0', '1', false],
]);

it('refuses --password-stdin without a terminal with exit 2 before any download or Docker call: its stdin is the script (ADR 0070)', function (array $extra): void {
    $process = $this->scripts->run($this->production, ['--dir', $this->dir, '--smtp-host', 'smtp.example.com', '--smtp-username', 'relay', '--password-stdin', ...$extra], $this->env);
    $error = json_decode($process->getOutput(), true)['errors'][0] ?? [];

    expect($process->getExitCode())->toBe(2, $process->getErrorOutput())
        ->and($process->getErrorOutput())->toContain('--password-stdin cannot read the SMTP password here')
        ->and($this->scripts->calls())->toBe([])
        ->and(file_exists($this->dir))->toBeFalse();
    if ($extra !== []) {
        expect($error['code'] ?? null)->toBe('password_stdin_unavailable')
            ->and($error['hint'])->toContain('--password-file=<path>');
    }
})->with(['human output' => [[]], '--json' => [['--json']]]);

it('refuses --passphrase-stdin of --from-backup the same way: exit 2 passphrase_stdin_unavailable before any download (M12 gate O7)', function (array $extra): void {
    $process = $this->scripts->run($this->production, ['--dir', $this->dir, '--from-backup', $this->dir.'-backup', '--identity-file', $this->dir.'-id.age', '--passphrase-stdin', ...$extra], $this->env);
    $envelope = json_decode($process->getOutput(), true) ?? [];
    $error = $envelope['errors'][0] ?? [];

    expect($process->getExitCode())->toBe(2, $process->getErrorOutput())
        ->and($process->getErrorOutput())->toContain('--passphrase-stdin cannot read the passphrase here')
        ->and($this->scripts->calls())->toBe([])
        ->and(file_exists($this->dir))->toBeFalse();
    if ($extra !== []) {
        expect($error['code'] ?? null)->toBe('passphrase_stdin_unavailable')
            ->and($error['hint'])->toContain('--passphrase-file=<path>')
            ->and($envelope['exit_code'] ?? null)->toBe(2); // O10: the numeric exit code in every envelope
    }
})->with(['human output' => [[]], '--json' => [['--json']]]);

it('lets fieldset prompt for --password-stdin only on a terminal without --json, as the shim hands it over', function (string $json, string $stdout, string $tty, bool $ok): void {
    $process = $this->scripts->source(base_path('distribution/install.sh'), "fieldset_install_stdin_ok {$json} {$stdout} {$tty} && echo prompt || echo refuse");

    expect(trim($process->getOutput()))->toBe($ok ? 'prompt' : 'refuse');
})->with([
    'person at a terminal' => ['0', '1', '1', true],
    '--json' => ['1', '1', '1', false],
    'stdout redirected' => ['0', '0', '1', false],
    'no terminal' => ['0', '1', '0', false],
]);

it('hands over to fieldset adopt with the 1.0 checkout mounted read-only for --adopt (ADR 0087)', function (array $arguments): void {
    mkdir($this->scripts->root.'/home/form-schema-app');
    $checkout = (string) realpath($this->scripts->root.'/home/form-schema-app');
    $arguments = array_map(fn (string $arg): string => str_replace('<checkout>', $this->scripts->root.'/home/form-schema-app', $arg), $arguments);

    $process = $this->scripts->run($this->production, ['--dir', $this->dir, ...$arguments, '--yes', '--json'], $this->env);
    $ops = $this->scripts->opsRuns()[0] ?? [];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($ops)->toContain('-v', "{$checkout}:{$checkout}:ro")
        ->and(array_slice($ops, array_search('--entrypoint', $ops, true) + 3))->toBe(['adopt', '--dir', $this->dir, $checkout, '--yes', '--json'])
        ->and($this->scripts->opsEnvironment()['FIELDSET_MOUNTS'])->toBe("ro:{$checkout}\n");
})->with([
    'separate value' => [['--adopt', '<checkout>']],
    'with =' => [['--adopt=<checkout>']],
]);

it('passes --password-file on as an input file flag, mounted read-only', function (): void {
    mkdir($this->scripts->root.'/keys');
    file_put_contents($this->scripts->root.'/keys/smtp-password', 'relay-secret');
    $process = $this->scripts->run($this->production, ['--dir', $this->dir, '--smtp-host', 'smtp.example.com', '--smtp-username', 'relay', '--password-file', $this->scripts->root.'/keys/smtp-password'], $this->env);
    $ops = $this->scripts->opsRuns()[0] ?? [];
    $keys = (string) realpath($this->scripts->root.'/keys');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($ops)->toContain('-v', "{$keys}:{$keys}:ro")
        ->and($ops)->toContain('--password-file='.$keys.'/smtp-password', '--smtp-host', 'smtp.example.com', '--smtp-username', 'relay')
        ->and(implode(' ', $ops))->not->toContain('relay-secret');
});

it('links with --yes only when --link is given', function (): void {
    $quiet = $this->scripts->run($this->production, ['--dir', $this->dir, '--yes'], $this->env);
    $linked = $this->scripts->run($this->production, ['--dir', $this->dir, '--yes', '--link'], $this->env);

    expect($quiet->getExitCode())->toBe(0, $quiet->getErrorOutput())
        ->and($this->scripts->opsRuns()[0])->toContain('--yes')
        ->and($linked->getExitCode())->toBe(0)
        ->and(readlink($this->scripts->root.'/home/.local/bin/fieldset'))->toBe($this->dir.'/fieldset')
        ->and($quiet->getErrorOutput())->not->toContain('linked');
})->skip(fn (): bool => posix_getuid() === 0, 'as root install.sh links into /usr/local/bin');

it('exits 10 when Docker is not installed', function (): void {
    unlink($this->scripts->bin.'/docker');

    $process = $this->scripts->run($this->production, ['--dir', $this->dir], [...$this->env, 'PATH' => $this->scripts->bin.':/usr/bin:/bin']);

    expect($process->getExitCode())->toBe(10)
        ->and($process->getErrorOutput())->toContain('Docker is not installed');
})->skip(fn (): bool => is_executable('/usr/bin/docker') || is_executable('/bin/docker'), 'a system docker binary in /usr/bin shadows the missing-Docker case');

it('links fieldset into the PATH on request but never replaces an existing fieldset (ADR 0049)', function (): void {
    $linked = $this->scripts->run($this->production, ['--dir', $this->dir, '--link'], $this->env);
    $link = $this->scripts->root.'/home/.local/bin/fieldset';
    $target = readlink($link);
    unlink($link);
    file_put_contents($link, "#!/bin/sh\necho other fieldset\n");
    $kept = $this->scripts->run($this->production, ['--dir', $this->dir, '--link'], $this->env);
    $failed = $this->scripts->run($this->production, ['--dir', $this->dir.'-2', '--link'], [...$this->env, 'STUB_OPS_EXIT' => '4']);

    expect($linked->getExitCode())->toBe(0)
        ->and($target)->toBe($this->dir.'/fieldset')
        ->and($linked->getErrorOutput())->toContain('linked')
        ->and($kept->getErrorOutput())->toContain('already exists and was left alone')
        ->and(file_get_contents($link))->toBe("#!/bin/sh\necho other fieldset\n")
        ->and($failed->getExitCode())->toBe(4)
        ->and($this->scripts->opsRuns()[0])->not->toContain('--link');
})->skip(fn (): bool => posix_getuid() === 0, 'as root install.sh links into /usr/local/bin');

it('prints usage for --help without side effects', function (): void {
    $process = $this->scripts->run($this->production, ['--help'], $this->env);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('Usage:')
        ->and($process->getOutput())->toContain('releases/download/v<version>')
        // The flags live in each command's --help; the 2.0 guides (U21), the agent guide among them, are named too.
        ->and($process->getOutput())->toContain('fieldset install --help')->toContain('<dir>/fieldset <command> --help')
        ->and($process->getOutput())->toContain('docs/guide/install.md')
        ->and($process->getOutput())->toContain('docs/guide/agent-guide.md')
        ->and($this->scripts->calls())->toBe([]);
});

it('documents the pre-release one-liner, because releases/latest exists only from 2.0.0 on', function (): void {
    $header = implode("\n", array_slice(explode("\n", (string) file_get_contents($this->production)), 0, 16));

    expect($header)->toContain('releases/download/v<version>/install.sh | sh -s -- --version <version>')
        ->and($header)->toContain('From 2.0.0 on')
        ->and(is_file(base_path('docs/guide/install.md')))->toBeTrue();
});

it('generates the harness build by flipping exactly the build line', function (): void {
    $production = (string) file_get_contents($this->production);
    $harness = InstallScriptBuild::harness($production);

    expect(substr_count($production, "\tbuild=production "))->toBe(1)
        ->and(preg_match('/^\s+build=harness/m', $production))->toBe(0)
        ->and($harness)->toBe(str_replace("\tbuild=production ", "\tbuild=harness ", $production))
        ->and(fn () => InstallScriptBuild::harness($harness))->toThrow(RuntimeException::class, 'exactly one');
});
