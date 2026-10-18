<?php

declare(strict_types=1);

use App\Domain\Instance\Console\FilePathFlags;
use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Console\LaunchGuard;
use App\Domain\Instance\Docker\DockerRuntime;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Output\SecretRedactor;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\Instance\ScriptedFsaCommand;
use Tests\Support\Instance\TemporaryInstance;

/*
 * U4 / M9-T07: the host-facts contract of the shim and install.sh (KTD2, §6.2, ADR 0070) and the checks every
 * fsa command makes when a launcher started it: the nonce must be visible (exit 3, file sharing) and file-path
 * flags must point into mounted directories (exit 2).
 */

const LAUNCH_NONCE = '.fsa-nonce-0123456789abcdef0123456789abcdef';

beforeEach(function (): void {
    $this->directory = TemporaryInstance::withState();
    // A host directory the launcher would mount: not under the system temp directory, which is reserved (/tmp, /var).
    $this->outside = base_path('storage/fsa-test-dist/launch-'.bin2hex(random_bytes(6)));
    mkdir($this->outside, 0700, true);
    $this->command = ScriptedFsaCommand::register(fn (FsaContext $c): CommandResult => CommandResult::ok('scripted', ['nonce' => $c->facts->nonce]));
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
    exec('rm -rf '.escapeshellarg($this->outside).' '.escapeshellarg($this->outside.'-other'));
    @rmdir(dirname($this->outside));
});

/** @param  array<string, bool>  $mounts */
function launchedFacts(string $directory, array $mounts = [], ?string $nonce = LAUNCH_NONCE): HostFacts
{
    $facts = new HostFacts(tty: false, uid: 501, instanceDir: $directory, launcher: 'shim', nonce: $nonce, mounts: $mounts);
    app()->instance(HostFacts::class, $facts);

    return $facts;
}

/** @return array{0: int, 1: array<string, mixed>} */
function launchedRun(array $parameters = []): array
{
    $code = Artisan::call('fsa:scripted', ['--json' => true, ...$parameters]);

    return [$code, json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR)];
}

it('reads the whole host-facts contract and drops invalid values', function (): void {
    $facts = HostFacts::fromEnvironment([
        'FSA_LAUNCHER' => 'install.sh', 'FSA_TTY' => '1', 'FSA_HOST_UID' => '1000', 'FSA_HOST_GID' => '1000', 'FSA_HOST_OS' => 'linux',
        'FSA_INSTANCE_DIR' => '/srv/fsa/', 'FSA_LAN_ADDRESSES' => '192.168.1.20 10.0.0.5  fe80::1 not-an-ip 192.168.1.20 ',
        'FSA_DESKTOP' => '0', 'FSA_TZ' => 'Europe/Berlin', 'FSA_DOCKER_RUNTIME' => 'rootful', 'FSA_CONTAINER_UID' => '1000',
        'FSA_DOCKER_SOCKET' => '/var/run/docker.sock', 'FSA_NONCE' => LAUNCH_NONCE,
        'FSA_MOUNTS' => "ro:/home/u/keys\nrw:/home/u/out\nro:/home/u/out\nxx:/bad\nrw:relative\n", 'FSA_OPEN_URL_FILE' => '.fsa-open-url-00112233445566778899aabbccddeeff',
    ]);
    $invalid = HostFacts::fromEnvironment([
        'FSA_LAUNCHER' => 'curl', 'FSA_HOST_OS' => 'Linux; rm', 'FSA_INSTANCE_DIR' => 'relative', 'FSA_TZ' => '../../etc/passwd',
        'FSA_DOCKER_RUNTIME' => 'podman', 'FSA_DOCKER_SOCKET' => "/var/run/docker.sock\n", 'FSA_NONCE' => '../.fsa-nonce-0123456789abcdef',
        'FSA_OPEN_URL_FILE' => '/tmp/url',
    ]);

    expect($facts->launcher)->toBe('install.sh')
        ->and($facts->tty)->toBeTrue()
        ->and($facts->instanceDir)->toBe('/srv/fsa')
        ->and($facts->lanAddresses)->toBe(['192.168.1.20', '10.0.0.5', 'fe80::1'])
        ->and($facts->desktop)->toBeFalse()
        ->and($facts->timezone)->toBe('Europe/Berlin')
        ->and($facts->runtime)->toBe(DockerRuntime::Rootful)
        ->and($facts->containerUid)->toBe(1000)
        ->and($facts->socket)->toBe('/var/run/docker.sock')
        ->and($facts->nonce)->toBe(LAUNCH_NONCE)
        ->and($facts->launched())->toBeTrue()
        ->and($facts->mounts)->toBe(['/home/u/keys' => false, '/home/u/out' => true])
        ->and($facts->writableMounts())->toBe(['/home/u/out'])
        ->and($facts->openUrlFile)->toBe('.fsa-open-url-00112233445566778899aabbccddeeff')
        ->and([$invalid->launcher, $invalid->os, $invalid->instanceDir, $invalid->timezone, $invalid->runtime, $invalid->socket, $invalid->nonce, $invalid->openUrlFile])
        ->each->toBeNull()
        ->and($invalid->launched())->toBeFalse()
        ->and(HostFacts::fromEnvironment(['FSA_INSTANCE_DIR' => '/'])->instanceDir)->toBe('/');
});

it('derives the in-container owner UID from the runtime (KTD2)', function (): void {
    expect(new HostFacts(uid: 1000, containerUid: 1000)->ownerUidInContainers())->toBe(1000)
        ->and(new HostFacts(uid: 1000, runtime: DockerRuntime::Rootful)->ownerUidInContainers())->toBe(1000)
        ->and(new HostFacts(uid: 1000, runtime: DockerRuntime::Rootless)->ownerUidInContainers())->toBe(0)
        ->and(new HostFacts(uid: 501, runtime: DockerRuntime::Desktop)->ownerUidInContainers())->toBe(0)
        ->and(new HostFacts(uid: 501)->ownerUidInContainers())->toBe(501);
});

it('runs a launched command when the nonce is visible in the instance directory and every writable mount', function (): void {
    touch($this->directory->root.'/'.LAUNCH_NONCE);
    touch($this->outside.'/'.LAUNCH_NONCE);
    launchedFacts($this->directory->root, [$this->outside => true]);

    [$code, $json] = launchedRun(['--dir' => $this->directory->root, '--secrets-file' => $this->outside.'/link.json']);

    expect($code)->toBe(0)
        ->and($json['data']['nonce'])->toBe(LAUNCH_NONCE)
        ->and($this->command->performed)->toBe(1);
});

it('fails with exit 3 and a file-sharing hint when the container cannot see the nonce', function (?string $where): void {
    touch($this->directory->root.'/'.LAUNCH_NONCE);
    if ($where === 'instance') {
        unlink($this->directory->root.'/'.LAUNCH_NONCE);
    }
    launchedFacts($this->directory->root, [$this->outside => true]);
    if ($where === null) {
        touch($this->outside.'/'.LAUNCH_NONCE);
    }

    [$code, $json] = launchedRun(['--dir' => $this->directory->root]);

    expect($code)->toBe($where === null ? 0 : 3);
    if ($where !== null) {
        expect($json['errors'][0]['code'])->toBe('path_not_shared')
            ->and($json['errors'][0]['hint'])->toContain('File sharing')
            ->and($json['data']['path'])->toBe($where === 'instance' ? $this->directory->root : $this->outside)
            ->and($this->command->performed)->toBe(0);
    }
})->with(['instance directory' => 'instance', 'writable mount' => 'mount', 'both visible' => null]);

it('rejects a file-path flag outside every mounted directory with exit 2', function (array $mounts, string $flag, string $path, int $expected): void {
    touch($this->directory->root.'/'.LAUNCH_NONCE);
    foreach (array_keys(array_filter($mounts)) as $directory) {
        touch(str_replace('{outside}', $this->outside, $directory).'/'.LAUNCH_NONCE);
    }
    $resolved = [];
    foreach ($mounts as $directory => $writable) {
        $resolved[str_replace('{outside}', $this->outside, $directory)] = $writable;
    }
    launchedFacts($this->directory->root, $resolved);
    $path = str_replace(['{outside}', '{instance}'], [$this->outside, $this->directory->root], $path);

    [$code, $json] = launchedRun(['--dir' => $this->directory->root, $flag => $path]);

    expect($code)->toBe($expected);
    if ($expected === 2) {
        expect($json['errors'][0]['code'])->toBe('path_not_mounted')
            ->and($json['errors'][0]['message'])->toContain($flag);
    }
})->with([
    'output in a writable mount' => [['{outside}' => true], '--secrets-file', '{outside}/link.json', 0],
    'output in the instance directory' => [[], '--secrets-file', '{instance}/link.json', 0],
    'output in a read-only mount' => [['{outside}' => false], '--secrets-file', '{outside}/link.json', 2],
    'output nowhere mounted' => [[], '--secrets-file', '{outside}/link.json', 2],
    'input in a read-only mount' => [['{outside}' => false], '--smtp-password-file', '{outside}/pw', 0],
    'input below a mounted parent' => [['{outside}' => false], '--smtp-password-file', '{outside}/sub/pw', 0],
    'input nowhere mounted' => [[], '--smtp-password-file', '/etc/shadow', 2],
    'relative path' => [[], '--smtp-password-file', 'pw', 2],
    'sibling with the same prefix' => [['{outside}' => false], '--smtp-password-file', '{outside}-other/pw', 2],
]);

it('refuses a reserved mount before the nonce check and the command, with exit 2 (an older launcher)', function (string $mount): void {
    touch($this->directory->root.'/'.LAUNCH_NONCE);
    launchedFacts($this->directory->root, [$this->outside => true, $mount => false]);
    touch($this->outside.'/'.LAUNCH_NONCE);

    [$code, $json] = launchedRun(['--dir' => $this->directory->root]);

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe('path_reserved')
        ->and($json['errors'][0]['message'])->toContain($mount)
        ->and($this->command->performed)->toBe(0);
})->with([
    'shared /tmp (HOME used to live there)' => ['/tmp'],
    'below /tmp' => ['/tmp/x'],
    'the app code' => ['/var/www'],
    'image binaries' => ['/usr/local/bin'],
    '/home itself' => ['/home'],
    'dot segments into /tmp' => ['/home/u/../../tmp'],
]);

it('reserves system paths of the image and shared writable directories, never home or /opt', function (): void {
    expect(array_filter(['/', '/home', '/tmp', '/tmp/a', '/var/tmp', '/var/www/html', '/usr/local/lib/docker/cli-plugins', '/etc', '/run/fsa-home', '/proc/self',
        '/sys', '/dev/shm', '/bin', '/sbin', '/lib', '/lib64/x', '/root', '/home/u/../../etc', '/./tmp//x/'], LaunchGuard::reserved(...)))->toHaveCount(19)
        ->and(array_filter(['/home/u', '/home/u/fsa', '/opt/form-schema-app', '/srv/fsa', '/Users/u/out', '/tmpx', '/var2', '/usrlocal', '/mnt/tmp'], LaunchGuard::reserved(...)))->toBe([]);
});

it('checks nothing for a command no launcher started', function (): void {
    app()->instance(HostFacts::class, new HostFacts(tty: false, uid: 501));

    [$code] = launchedRun(['--dir' => $this->directory->root, '--smtp-password-file' => '/etc/shadow']);

    expect($code)->toBe(0);
});

it('classifies file-path flags like the shim', function (): void {
    expect(array_map(FilePathFlags::classify(...), [
        'secrets-file', 'output', 'backup-out', 'password-file', 'owner-password-file', 'from-backup', 'dir', 'json', 'file', 'outputs', 'version',
    ]))->toBe(['out', 'out', 'out', 'in', 'in', 'in', null, null, null, null, null]);
});

it('hands a browser URL to the shim through a 0600 file only with a desktop session', function (): void {
    $context = fn (HostFacts $facts): FsaContext => new FsaContext($facts, new ProgressReporter(new NullOutput, false, new SecretRedactor), new SecretRedactor, true, false, $this->directory);
    $file = '.fsa-open-url-00112233445566778899aabbccddeeff';

    $opened = $context(new HostFacts(desktop: true, openUrlFile: $file))->openInBrowser('https://localhost:8080/login-link#token=abc');
    $mode = fileperms($this->directory->root.'/'.$file) & 0777;

    expect($opened)->toBeTrue()
        ->and(file_get_contents($this->directory->root.'/'.$file))->toBe("https://localhost:8080/login-link#token=abc\n")
        ->and($mode)->toBe(0600)
        ->and($context(new HostFacts(desktop: false, openUrlFile: $file))->openInBrowser('https://x.test/'))->toBeFalse()
        ->and($context(new HostFacts(desktop: true))->openInBrowser('https://x.test/'))->toBeFalse()
        ->and($context(new HostFacts(desktop: true, openUrlFile: $file))->openInBrowser('file:///etc/passwd'))->toBeFalse()
        ->and($context(new HostFacts(desktop: true, openUrlFile: $file))->openInBrowser('https://x.test/$(touch /tmp/x) y'))->toBeFalse()
        ->and(new FsaContext(new HostFacts(desktop: true, openUrlFile: $file), new ProgressReporter(new NullOutput, false, new SecretRedactor), new SecretRedactor, true, false)->openInBrowser('https://x.test/'))->toBeFalse();
});
