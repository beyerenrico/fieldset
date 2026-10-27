<?php

declare(strict_types=1);

use App\Domain\Instance\Console\LaunchGuard;
use App\Domain\Instance\Docker\DockerInfo;
use App\Domain\Instance\State\InstanceState;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\DistributionScripts;

/*
 * 2.1.1 U2: WSL in distribution/shim.sh and install.sh (R6, R16, R25, R26, KTD11). WSL 2 is a "microsoft" kernel
 * release plus an interop marker or WSL_DISTRO_NAME; the shim passes it and the Docker backend to the ops container.
 * WSL 1 and an instance directory on a Windows drive are refused before any Docker call; a missing or stopped Docker
 * names the Docker Desktop step. /proc is simulated under FIELDSET_TEST_PROC (DistributionScripts::wsl(), proc()).
 */

const WSL_SHIM_IMAGE = 'ghcr.io/beyerenrico/fieldset@sha256:1111111111111111111111111111111111111111111111111111111111111111';

beforeEach(function (): void {
    $this->scripts = new DistributionScripts;
    mkdir($this->scripts->root.'/home');
    mkdir($this->scripts->root.'/wind');
    $this->root = (string) realpath($this->scripts->root);
    $this->install = DistributionScripts::repository().'/distribution/install.sh';
    $this->env = ['STUB_UNAME' => 'Linux', 'STUB_DOCKER_INFO' => 'Docker Desktop|docker-desktop|[]'];
});

afterEach(fn () => $this->scripts->cleanUp());

/** An instance directory as install.sh leaves it (state and shim); returns the shim's path. */
function wslInstance(string $dir): string
{
    if (! is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $state = InstanceState::create('3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $dir, 1000, 1000, 1000)
        ->withRelease('2.1.1', ['app' => WSL_SHIM_IMAGE, 'postgres' => 'docker.io/library/postgres@sha256:'.str_repeat('2', 64)]);
    file_put_contents($dir.'/state.json', $state->toJson());
    copy(base_path('distribution/shim.sh'), $dir.'/fieldset');
    chmod($dir.'/fieldset', 0700);

    return $dir.'/fieldset';
}

/** A mount table as WSL 2 writes it, plus the given lines; `drvfs:<drive>:<mount point>` is a Windows drive (9p, aname=drvfs). */
function wslMounts(string ...$lines): string
{
    $lines = array_map(static fn (string $line): string => str_starts_with($line, 'drvfs:')
        ? sprintf('%1$s:\134 %2$s 9p rw,noatime,dirsync,aname=drvfs;path=%1$s:\;uid=1000;gid=1000;symlinkroot=/mnt/,mmap,access=client,msize=65536,trans=fd,rfd=5,wfd=5 0 0', ...explode(':', substr($line, 6), 2))
        : $line, $lines);

    return implode("\n", ['/dev/sdc / ext4 rw,relatime,discard,errors=remount-ro,data=ordered 0 0', 'none /mnt/wsl tmpfs rw,relatime 0 0',
        'drivers /usr/lib/wsl/drivers 9p ro,nosuid,nodev,noatime,dirsync,aname=drivers;fmask=222;dmask=222,mmap,access=client,msize=65536,trans=fd,rfd=7,wfd=7 0 0', ...$lines])."\n";
}

/** @return array<string, mixed> the --json envelope's first error */
function wslError(Process $process): array
{
    return json_decode($process->getOutput(), true)['errors'][0] ?? [];
}

it('recognises WSL 2 only with an interop marker or WSL_DISTRO_NAME, and WSL 1 by its kernel, in the shim and install.sh alike', function (?string $kernel, ?string $marker, ?string $distro, string $expected): void {
    if ($kernel !== null) {
        $this->scripts->wsl($kernel, $marker);
    }
    $env = ['WSL_DISTRO_NAME' => $distro ?? false];

    $shim = $this->scripts->source(base_path('distribution/shim.sh'), 'fieldset_wsl_detect; printf %s "$fieldset_wsl"', $env);
    $install = $this->scripts->source($this->install, 'fieldset_install_wsl; printf %s "$wsl"', $env);

    expect($shim->getOutput())->toBe($expected, $shim->getErrorOutput())
        ->and($install->getOutput())->toBe($expected, $install->getErrorOutput());
})->with([
    'WSL 2' => [DistributionScripts::WSL2_KERNEL, 'WSLInterop', null, '2'],
    'WSL 2, interop registered late' => [DistributionScripts::WSL2_KERNEL, 'WSLInterop-late', null, '2'],
    'WSL 2 with interop off, WSL_DISTRO_NAME set' => [DistributionScripts::WSL2_KERNEL, null, 'Ubuntu', '2'],
    'early WSL 2 kernel without the WSL2 suffix' => ['4.19.104-microsoft-standard', 'WSLInterop', null, '2'],
    'WSL 1' => [DistributionScripts::WSL1_KERNEL, 'WSLInterop', null, '1'],
    'a "microsoft" kernel without marker or WSL_DISTRO_NAME (a cloud VM)' => ['5.15.153.1-microsoft-standard', null, null, '0'],
    'a plain Linux kernel next to an interop marker' => ['6.8.0-49-generic', 'WSLInterop', null, '0'],
    'no /proc (macOS)' => [null, null, null, '0'],
]);

it('passes WSL 2 and the Docker backend, read from OperatingSystem by prefix as DockerInfo reads it', function (string $os, string $backend): void {
    $this->scripts->wsl();
    $shim = wslInstance($this->scripts->root.'/home/fieldset');

    $process = $this->scripts->run($shim, ['status'], [...$this->env, 'STUB_DOCKER_INFO' => "{$os}|DESKTOP-PC|[]"]);
    $facts = $this->scripts->opsEnvironment();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and([$facts['FIELDSET_WSL'], $facts['FIELDSET_WSL_BACKEND']])->toBe(['2', $backend])
        ->and(DockerInfo::fromJson(['OperatingSystem' => $os])->wslBackend()->value)->toBe($backend);
})->with([
    'Docker Desktop' => ['Docker Desktop', 'desktop'],
    'Engine in Ubuntu' => ['Ubuntu 24.04 LTS', 'engine'],
    'Engine in Ubuntu on a newer WSL' => ['Ubuntu 22.04.5 LTS (containerized)', 'engine'],
    'Engine in Debian' => ['Debian GNU/Linux 12 (bookworm)', 'engine'],
]);

it('does not treat a "microsoft" kernel without an interop marker as WSL (cloud kernel)', function (): void {
    $this->scripts->wsl('5.15.153.1-microsoft-standard', null);
    $shim = wslInstance($this->scripts->root.'/home/fieldset');

    $process = $this->scripts->run($shim, ['status'], [...$this->env, 'STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|cloud-vm|[]']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($this->scripts->opsEnvironment())->toMatchArray(['FIELDSET_WSL' => '0', 'FIELDSET_WSL_BACKEND' => '', 'FIELDSET_DOCKER_RUNTIME' => 'rootful']);
});

it('refuses WSL 1 with the command that converts the distro, before any Docker call (R25)', function (string $launcher, ?string $distro, string $command): void {
    $this->scripts->wsl(DistributionScripts::WSL1_KERNEL);
    $dir = $this->scripts->root.'/home/fieldset';
    $env = [...$this->env, 'WSL_DISTRO_NAME' => $distro ?? false];

    $process = $launcher === 'shim'
        ? $this->scripts->run(wslInstance($dir), ['status', '--json'], $env)
        : $this->scripts->run($this->install, ['--dir', $dir, '--json'], $env);

    expect($process->getExitCode())->toBe(3, $process->getErrorOutput())
        ->and(wslError($process))->toMatchArray(['code' => 'wsl1_unsupported', 'retryable' => false])
        ->and(wslError($process)['hint'])->toContain($command)
        ->and($this->scripts->calls())->toBe([])
        ->and(glob($dir.'/.fieldset-nonce-*'))->toBe([])
        ->and($launcher === 'shim' || ! file_exists($dir))->toBeTrue();
})->with([
    'shim' => ['shim', 'Ubuntu', 'wsl --set-version Ubuntu 2'],
    'install.sh' => ['install.sh', 'Ubuntu-24.04', 'wsl --set-version Ubuntu-24.04 2'],
    'install.sh, distro name unknown' => ['install.sh', null, 'wsl --set-version <distro> 2'],
]);

it('tells a Windows drive by the file system of the real path, in the shim and install.sh alike (KTD11)', function (array $mounts, string $path, string $wsl, bool $expected, ?Closure $setup = null): void {
    $path = str_replace('{root}', $this->root, $path);
    $mounts = array_map(fn (string $line): string => str_replace('{root}', $this->root, $line), $mounts);
    if ($mounts !== []) {
        $this->scripts->proc('mounts', wslMounts(...$mounts));
    }
    if ($setup instanceof Closure) {
        $setup($this->root);
    }
    $env = ['P' => $path, 'W' => $wsl];

    $shim = $this->scripts->source(base_path('distribution/shim.sh'), 'fieldset_wsl=$W; fieldset_windows_drive "$P" && printf 1 || printf 0', $env);
    $install = $this->scripts->source($this->install, 'wsl=$W; fieldset_install_windows_drive "$P" && printf 1 || printf 0', $env);

    expect($shim->getOutput())->toBe($expected ? '1' : '0', $shim->getErrorOutput())
        ->and($install->getOutput())->toBe($expected ? '1' : '0', $install->getErrorOutput());
})->with([
    'under /mnt/c' => [['drvfs:C:/mnt/c'], '/mnt/c/Users/x/fieldset', '2', true],
    'the drive itself' => [['drvfs:C:/mnt/c'], '/mnt/c', '2', true],
    'custom automount root (drvfs at /c)' => [['drvfs:C:/c'], '/c/Users/x/fieldset', '2', true],
    'a link to a Windows drive' => [['drvfs:D:{root}/wind'], '{root}/home/fieldset', '2', true, function (string $root): void {
        mkdir($root.'/wind/fieldset');
        symlink($root.'/wind/fieldset', $root.'/home/fieldset');
    }],
    'a new directory below a linked parent' => [['drvfs:D:{root}/wind'], '{root}/home/data/new/fieldset', '2', true, fn (string $root): bool => symlink($root.'/wind', $root.'/home/data')],
    'a mount point with a space (\040)' => [['drvfs:E:{root}/wind\040e'], '{root}/wind e/fieldset', '2', true],
    'drvfs without WSL facts (sudo dropped WSL_DISTRO_NAME, interop off)' => [['drvfs:C:/mnt/c'], '/mnt/c/Users/x/fieldset', '0', true],
    'a 9p share on WSL 2' => [['share {root}/share 9p rw,trans=virtio 0 0'], '{root}/share/fieldset', '2', true],
    'a 9p share on a Linux VM' => [['share {root}/share 9p rw,trans=virtio 0 0'], '{root}/share/fieldset', '0', false],
    'the Linux home on WSL 2' => [['drvfs:C:/mnt/c'], '{root}/home/fieldset', '2', false],
    'next to the drive mount' => [['drvfs:C:/mnt/c'], '/mnt/cc/fieldset', '2', false],
    'no mount table (macOS)' => [[], '/mnt/c/Users/x/fieldset', '0', false],
]);

it('install.sh refuses an instance directory on a Windows drive before creating anything and names ~/fieldset (AE3)', function (string $dir, ?Closure $setup = null): void {
    $this->scripts->wsl();
    $this->scripts->proc('mounts', wslMounts('drvfs:C:/mnt/c', "drvfs:D:{$this->root}/wind"));
    if ($setup instanceof Closure) {
        $setup($this->root);
    }
    $dir = str_replace('{root}', $this->root, $dir);

    $process = $this->scripts->run($this->install, ['--dir', $dir, '--json'], $this->env);

    expect($process->getExitCode())->toBe(2, $process->getErrorOutput())
        ->and(wslError($process))->toMatchArray(['code' => 'path_on_windows_drive', 'retryable' => false])
        ->and(wslError($process)['message'])->toContain($dir)->toContain('Windows drive')
        ->and(wslError($process)['hint'])->toContain('--dir ~/fieldset')
        ->and($this->scripts->calls())->toBe([])
        ->and(glob($this->root.'/wind/fieldset/*'))->toBe([])
        ->and(is_dir($this->root.'/home/fieldset') && ! is_link($this->root.'/home/fieldset'))->toBeFalse();
})->with([
    'under /mnt/c' => ['/mnt/c/Users/x/fieldset'],
    '~/fieldset links to a Windows drive' => ['{root}/home/fieldset', function (string $root): void {
        mkdir($root.'/wind/fieldset');
        symlink($root.'/wind/fieldset', $root.'/home/fieldset');
    }],
]);

it('refuses to run an instance on a Windows drive, also through a link, before any Docker call', function (bool $link): void {
    $this->scripts->wsl();
    $this->scripts->proc('mounts', wslMounts("drvfs:D:{$this->root}/wind"));
    $shim = wslInstance($this->root.'/wind/fieldset');
    if ($link) {
        symlink($this->root.'/wind/fieldset', $this->root.'/home/fieldset');
        $shim = $this->root.'/home/fieldset/fieldset';
    }

    $process = $this->scripts->run($shim, ['status', '--json'], $this->env);

    expect($process->getExitCode())->toBe(2, $process->getErrorOutput())
        ->and(wslError($process)['code'])->toBe('path_on_windows_drive')
        ->and(wslError($process)['hint'])->toContain('~/fieldset')
        ->and($this->scripts->calls())->toBe([])
        ->and(glob($this->root.'/wind/fieldset/.fieldset-nonce-*'))->toBe([]);
})->with(['the instance directory' => false, '~/fieldset linked to it' => true]);

it('allows backups and file flags on a Windows drive: only the instance directory is checked, the reserved paths stay (KTD11)', function (): void {
    $this->scripts->wsl();
    $this->scripts->proc('mounts', wslMounts("drvfs:C:{$this->root}/wind"));
    mkdir($this->root.'/wind/keys');
    file_put_contents($this->root.'/wind/keys/pass', 'secret');
    $shim = wslInstance($this->root.'/home/fieldset');

    $process = $this->scripts->run($shim, ['backup', '--encrypt', '--identity-out', $this->root.'/wind/keys/identity.txt', '--passphrase-file', $this->root.'/wind/keys/pass'], $this->env);
    $run = $this->scripts->opsRuns()[0];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($run)->toContain("{$this->root}/wind/keys:{$this->root}/wind/keys")
        ->and($this->scripts->opsEnvironment()['FIELDSET_MOUNTS'])->toBe("rw:{$this->root}/wind/keys\nro:{$this->root}/wind/keys\n")
        ->and(LaunchGuard::RESERVED)->not->toContain('/mnt')
        ->and(LaunchGuard::reserved('/mnt/c/Users/x/backups'))->toBeFalse();
});

it('names Docker Desktop\'s WSL integration and Docker Engine in WSL when docker is missing on WSL 2 (R26)', function (string $launcher): void {
    $this->scripts->wsl();
    unlink($this->scripts->bin.'/docker');
    $dir = $this->scripts->root.'/home/fieldset';
    $env = [...$this->env, 'WSL_DISTRO_NAME' => 'Ubuntu'];

    $process = $launcher === 'shim'
        ? $this->scripts->run(wslInstance($dir), ['status', '--json'], $env)
        : $this->scripts->run($this->install, ['--dir', $dir, '--json'], $env);

    expect($process->getExitCode())->toBe(10, $process->getErrorOutput())
        ->and(wslError($process)['code'])->toBe('docker_missing')
        ->and(wslError($process)['message'])->toContain('WSL distro')
        ->and(wslError($process)['hint'])->toContain('Start Docker Desktop on Windows', 'WSL integration for Ubuntu', 'Docker Engine');
})->with(['shim', 'install.sh'])->skip(fn (): bool => is_executable('/usr/bin/docker') || is_executable('/bin/docker'), 'a system docker binary in /usr/bin shadows the missing-Docker case');

it('says "start Docker Desktop on Windows" when the socket is dead on WSL 2, and Docker missing when Docker asks for the WSL integration (R26)', function (string $launcher, array $env, string $code, string $hint): void {
    $this->scripts->wsl();
    if ($env === []) {
        $this->scripts->writeExecutable('docker', "#!/bin/sh\nprintf '%s\\n' \"The command 'docker' could not be found in this WSL 2 distro.\" 'We recommend to activate the WSL integration in Docker Desktop settings.' >&2\nexit 1\n");
    }
    $dir = $this->scripts->root.'/home/fieldset';

    $process = $launcher === 'shim'
        ? $this->scripts->run(wslInstance($dir), ['status', '--json'], [...$this->env, ...$env])
        : $this->scripts->run($this->install, ['--dir', $dir, '--json'], [...$this->env, ...$env]);

    expect($process->getExitCode())->toBe(10, $process->getErrorOutput())
        ->and(wslError($process))->toMatchArray(['code' => $code, 'retryable' => $code === 'docker_unavailable'])
        ->and(wslError($process)['hint'])->toContain($hint)
        ->and($this->scripts->opsRuns())->toBe([]);
})->with([
    'shim, daemon down' => ['shim', ['STUB_DOCKER_DOWN' => '1'], 'docker_unavailable', 'Start Docker Desktop on Windows'],
    'install.sh, daemon down' => ['install.sh', ['STUB_DOCKER_DOWN' => '1'], 'docker_unavailable', 'Start Docker Desktop on Windows'],
    'shim, WSL integration off' => ['shim', [], 'docker_missing', 'WSL integration'],
    'install.sh, WSL integration off' => ['install.sh', [], 'docker_missing', 'WSL integration'],
]);
