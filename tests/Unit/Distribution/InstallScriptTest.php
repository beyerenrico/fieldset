<?php

declare(strict_types=1);

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

it('refuses FSA_TRUST_KEY in the production build with exit 2 before any download or Docker call', function (): void {
    $process = $this->scripts->run($this->production, ['--dir', $this->dir, '--json'], [...$this->env, 'FSA_TRUST_KEY' => $this->key]);
    $json = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('FSA_TRUST_KEY is accepted only by release-harness builds')
        ->and($json['status'])->toBe('failed')
        ->and($json['command'])->toBe('install')
        ->and($json['errors'][0]['code'])->toBe('trust_key_not_allowed')
        ->and($this->scripts->calls())->toBe([])
        ->and(file_exists($this->dir))->toBeFalse();
});

it('allows plain-http release URLs only in the harness build', function (): void {
    $http = ['FSA_RELEASE_URL' => 'http://127.0.0.1:1234', 'STUB_DOCKER_DOWN' => '1'];
    $production = $this->scripts->run($this->production, ['--dir', $this->dir], [...$this->env, ...$http]);
    $harness = $this->scripts->run($this->harness, ['--dir', $this->dir], [...$this->env, ...$http]);

    expect($production->getExitCode())->toBe(2)
        ->and($production->getErrorOutput())->toContain('FSA_RELEASE_URL must be an https URL')
        ->and($harness->getExitCode())->toBe(10)
        ->and($this->scripts->run($this->production, ['--dir', $this->dir], [...$this->env, 'FSA_RELEASE_URL' => 'ftp://x'])->getExitCode())->toBe(2);
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
    $process = $this->scripts->run($this->harness, ['--dir', $this->dir, '--json'], [...$this->env, 'FSA_TRUST_KEY' => $this->key, 'STUB_VERIFY_EXIT' => '1']);
    $pulls = array_map(fn (array $call): string => end($call), array_filter($this->scripts->calls('docker'), fn (array $call): bool => $call[1] === 'pull'));

    expect($process->getExitCode())->toBe(3)
        ->and(json_decode($process->getOutput(), true)['errors'][0]['code'])->toBe('manifest_signature_invalid')
        ->and(json_decode($process->getOutput(), true)['errors'][0]['message'])->toContain('invalid signature')
        ->and(array_values($pulls))->toBe([ManifestVerifier::COSIGN_IMAGE])
        ->and($this->scripts->opsRuns())->toBe([])
        ->and(file_exists($this->dir))->toBeFalse()
        ->and(glob($this->scripts->root.'/fsa-install.*'))->toBe([]);
});

it('verifies with the local key and no network in the harness build', function (): void {
    $process = $this->scripts->run($this->harness, ['--dir', $this->dir], [...$this->env, 'FSA_TRUST_KEY' => $this->key, 'STUB_IMAGE_PRESENT' => '1']);
    $create = array_values(array_filter($this->scripts->calls('docker'), fn (array $call): bool => $call[1] === 'create'))[0];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_slice($create, 2))->toBe(['-e', 'HOME=/tmp', '--network', 'none', ManifestVerifier::COSIGN_IMAGE, 'verify-blob', '--bundle', '/verify/bundle.json', '--key', '/verify/trust.pub', '--insecure-ignore-tlog=true', '/verify/release-manifest.json'])
        ->and(file_get_contents($this->scripts->root.'/verified/trust.pub'))->toBe("-----BEGIN PUBLIC KEY-----\n")
        ->and(array_filter($this->scripts->calls('docker'), fn (array $call): bool => $call[1] === 'pull' && end($call) === ManifestVerifier::COSIGN_IMAGE))->toBe([])
        ->and($this->scripts->calls('curl')[0])->toContain('https://github.com/beyerenrico/form-schema-app/releases/latest/download/release-manifest.json');
});

it('creates the instance directory, stores the verified release and installs the shim from the image', function (): void {
    $process = $this->scripts->run($this->production, ['--dir', $this->dir.'//', '--json'], [...$this->env, 'FSA_REGISTRY' => '127.0.0.1:5000']);
    $pulled = '127.0.0.1:5000/beyerenrico/form-schema-app@'.ReleaseFixtures::APP_DIGEST;
    $cat = array_values(array_filter($this->scripts->calls('docker'), fn (array $call): bool => in_array('cat', $call, true)))[0];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe('')
        ->and(collect(explode("\n", trim($process->getErrorOutput())))->map(fn (string $line): array => json_decode($line, true) ?? [])->pluck('phase')->all())
        ->toBe(['verify', 'pull', 'handover'])
        ->and(substr(sprintf('%o', fileperms($this->dir)), -3))->toBe('700')
        ->and(file_get_contents($this->dir.'/.fsa-instance'))->toBe("{\"schema_version\":\"1.0\",\"kind\":\"form-schema-app instance\"}\n")
        ->and(file_get_contents($this->dir.'/releases/2.0.0-alpha.1/release-manifest.json'))->toBe($this->manifestJson)
        ->and(is_file($this->dir.'/releases/2.0.0-alpha.1/release-manifest.json.sigstore.json'))->toBeTrue()
        ->and(file_get_contents($this->dir.'/fsa'))->toBe(file_get_contents(base_path('distribution/shim.sh')))
        ->and(substr(sprintf('%o', fileperms($this->dir.'/fsa')), -3))->toBe('700')
        ->and(array_slice($cat, 1))->toBe(['run', '--rm', '--network', 'none', '--entrypoint', 'cat', $pulled, '/var/www/html/distribution/shim.sh'])
        ->and($this->scripts->opsRuns()[0])->toContain($pulled);
});

it('hands over to fsa install in an ops container with the host facts, mounts and user (KTD2)', function (): void {
    $hook = $this->scripts->writeExecutable('ops-hook', "#!/bin/sh\n[ -f \"\$FSA_INSTANCE_DIR/\$FSA_NONCE\" ] && touch \"\$STUB_ROOT/nonce-seen\"\n");
    $process = $this->scripts->run($this->production, ['--dir', $this->dir, '--owner-email', 'owner@example.test', '--json', '--yes'], [...$this->env, 'STUB_OPS_HOOK' => $hook, 'STUB_OPS_EXIT' => '6']);
    $run = $this->scripts->opsRuns()[0];
    $env = $this->scripts->opsEnvironment();
    $image = 'ghcr.io/beyerenrico/form-schema-app@'.ReleaseFixtures::APP_DIGEST;
    $uid = trim((string) shell_exec('id -u'));
    $gid = trim((string) shell_exec('id -g'));

    expect($process->getExitCode())->toBe(6)
        ->and(array_slice($run, 0, 18))->toBe([
            'run', '--rm', '--init', '--label', 'app.form-schema.role=ops', '--user', "{$uid}:{$gid}", '-e', 'HOME=/tmp', '-e', 'DOCKER_CONFIG=/tmp/.docker',
            '-e', 'DOCKER_HOST=unix:///var/run/docker.sock', '-v', '/var/run/docker.sock:/var/run/docker.sock', '-v', "{$this->dir}:{$this->dir}", '-w',
        ])
        ->and(array_slice($run, array_search('--entrypoint', $run, true)))->toBe([
            '--entrypoint', 'fsa', $image, 'install', '--owner-email', 'owner@example.test', '--json', '--yes', '--dir', $this->dir,
        ])
        ->and($run)->not->toContain('-i')
        ->and($run)->not->toContain('-it')
        ->and($env)->toMatchArray([
            'FSA_LAUNCHER' => 'install.sh', 'FSA_TTY' => '0', 'FSA_HOST_UID' => $uid, 'FSA_HOST_GID' => $gid, 'FSA_HOST_OS' => 'linux',
            'FSA_INSTANCE_DIR' => $this->dir, 'FSA_DESKTOP' => '0', 'FSA_TZ' => 'Europe/Berlin', 'FSA_DOCKER_RUNTIME' => 'rootful',
            'FSA_CONTAINER_UID' => $uid, 'FSA_DOCKER_SOCKET' => '/var/run/docker.sock', 'FSA_MOUNTS' => '', 'FSA_OPEN_URL_FILE' => '',
            'FSA_RELEASE_MANIFEST' => $this->dir.'/releases/2.0.0-alpha.1/release-manifest.json',
        ])
        ->and($env['FSA_NONCE'])->toMatch('/^\.fsa-nonce-[0-9a-f]{32}$/')
        ->and(is_file($this->scripts->root.'/nonce-seen'))->toBeTrue()
        ->and(glob($this->dir.'/.fsa-nonce-*'))->toBe([]);

    foreach (['FSA_LAUNCHER', 'FSA_TTY', 'FSA_HOST_UID', 'FSA_NONCE', 'FSA_MOUNTS', 'FSA_TRUST_KEY', 'FSA_REGISTRY', 'FSA_RELEASE_MANIFEST'] as $name) {
        expect(implode(' ', $run))->toContain("-e {$name} ");
    }
});

it('resolves the release and the image from the manifest, honouring FSA_REGISTRY', function (array $env, string $version, int $status, string $expected): void {
    $process = $this->scripts->source(base_path('distribution/install.sh'), 'json=0; version="'.$version.'"; fsa_install_read_manifest "'.$this->release.'/release-manifest.json" && printf "%s %s" "$release" "$ref"', $env);

    expect($process->getExitCode())->toBe($status)
        ->and($status === 0 ? $process->getOutput() : $process->getErrorOutput())->toContain($expected);
})->with([
    'canonical registry' => [[], '', 0, '2.0.0-alpha.1 ghcr.io/beyerenrico/form-schema-app@'.ReleaseFixtures::APP_DIGEST],
    'mirror' => [['FSA_REGISTRY' => 'mirror.example:5000'], '2.0.0-alpha.1', 0, 'mirror.example:5000/beyerenrico/form-schema-app@'],
    'other version' => [[], '2.0.0', 3, 'The manifest is for 2.0.0-alpha.1, not 2.0.0'],
]);

it('refuses a manifest in a format it cannot read with exit 3', function (): void {
    file_put_contents($this->release.'/release-manifest.json', '{"schema_version": 2, "version": "3.0.0"}');

    $process = $this->scripts->source(base_path('distribution/install.sh'), 'json=0; version=""; fsa_install_read_manifest "'.$this->release.'/release-manifest.json"');

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
    'bad registry' => [['--dir', '{dir}'], ['FSA_REGISTRY' => 'https://evil'], 'FSA_REGISTRY must be a registry host'],
    'dir with a colon' => [['--dir', '/tmp/a:b'], [], 'Cannot use /tmp/a:b'],
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
    $default = $this->scripts->source(base_path('distribution/install.sh'), 'nl="\n"; json=0; version=""; dir=""; build=production; fsa_install_check_inputs; printf %s "$dir"', $this->env);
    $relative = $this->scripts->source(base_path('distribution/install.sh'), 'nl="\n"; json=0; version=""; dir="sub/inst///"; build=production; fsa_install_check_inputs; printf %s "$dir"', $this->env);

    expect($backupsOnly->getExitCode())->toBe(0, $backupsOnly->getErrorOutput())
        ->and($this->scripts->run($this->production, ['--dir', $this->dir], $this->env)->getExitCode())->toBe(0)
        ->and($default->getOutput())->toBe(posix_getuid() === 0 ? '/opt/form-schema-app' : $this->scripts->root.'/home/form-schema-app')
        ->and($relative->getOutput())->toBe(realpath($this->scripts->root).'/sub/inst');
});

it('exits 10 when the Docker daemon is down and 3 when the release cannot be downloaded', function (): void {
    $down = $this->scripts->run($this->production, ['--dir', $this->dir, '--json'], [...$this->env, 'STUB_DOCKER_DOWN' => '1']);
    unlink($this->release.'/release-manifest.json.sigstore.json');
    $missing = $this->scripts->run($this->production, ['--dir', $this->dir, '--version', '2.0.0-alpha.1'], $this->env);

    expect($down->getExitCode())->toBe(10)
        ->and(json_decode($down->getOutput(), true)['errors'][0]['code'])->toBe('docker_unreachable')
        ->and($missing->getExitCode())->toBe(3)
        ->and($missing->getErrorOutput())->toContain('Cannot download https://github.com/beyerenrico/form-schema-app/releases/download/v2.0.0-alpha.1/release-manifest.json.sigstore.json')
        ->and(file_exists($this->dir))->toBeFalse();
});

it('exits 10 when Docker is not installed', function (): void {
    unlink($this->scripts->bin.'/docker');

    $process = $this->scripts->run($this->production, ['--dir', $this->dir], [...$this->env, 'PATH' => $this->scripts->bin.':/usr/bin:/bin']);

    expect($process->getExitCode())->toBe(10)
        ->and($process->getErrorOutput())->toContain('Docker is not installed');
})->skip(fn (): bool => is_executable('/usr/bin/docker') || is_executable('/bin/docker'), 'a system docker binary in /usr/bin shadows the missing-Docker case');

it('links fsa into the PATH on request but never replaces an existing fsa (ADR 0049)', function (): void {
    $linked = $this->scripts->run($this->production, ['--dir', $this->dir, '--link'], $this->env);
    $link = $this->scripts->root.'/home/.local/bin/fsa';
    $target = readlink($link);
    unlink($link);
    file_put_contents($link, "#!/bin/sh\necho other fsa\n");
    $kept = $this->scripts->run($this->production, ['--dir', $this->dir, '--link'], $this->env);
    $failed = $this->scripts->run($this->production, ['--dir', $this->dir.'-2', '--link'], [...$this->env, 'STUB_OPS_EXIT' => '4']);

    expect($linked->getExitCode())->toBe(0)
        ->and($target)->toBe($this->dir.'/fsa')
        ->and($linked->getErrorOutput())->toContain('linked')
        ->and($kept->getErrorOutput())->toContain('already exists and was left alone')
        ->and(file_get_contents($link))->toBe("#!/bin/sh\necho other fsa\n")
        ->and($failed->getExitCode())->toBe(4)
        ->and($this->scripts->opsRuns()[0])->not->toContain('--link');
})->skip(fn (): bool => posix_getuid() === 0, 'as root install.sh links into /usr/local/bin');

it('prints usage for --help without side effects', function (): void {
    $process = $this->scripts->run($this->production, ['--help'], $this->env);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('Usage:')
        ->and($this->scripts->calls())->toBe([]);
});

it('generates the harness build by flipping exactly the build line', function (): void {
    $production = (string) file_get_contents($this->production);
    $harness = InstallScriptBuild::harness($production);

    expect(substr_count($production, "\tbuild=production "))->toBe(1)
        ->and(preg_match('/^\s+build=harness/m', $production))->toBe(0)
        ->and($harness)->toBe(str_replace("\tbuild=production ", "\tbuild=harness ", $production))
        ->and(fn () => InstallScriptBuild::harness($harness))->toThrow(RuntimeException::class, 'exactly one');
});
