<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerRuntime;
use App\Domain\Instance\State\InstanceState;
use Carbon\CarbonImmutable;
use Tests\Support\Docker\DistributionScripts;

/*
 * 2.1.1 U3: the shim measures who owns instance files inside containers (KTD2, KTD3, KTD4; R2, R16, R27; AE2). Container
 * root of the state's image reads the owner of the nonce file and the mounted socket: 0 maps root to the host user (ops
 * as 0:0), the host UID passes IDs through (ops as the host user plus the socket's group), anything else stops. Install,
 * doctor and repair-ownership always measure; other commands use the owner saved in state.json unless there is none or
 * the runtime changed. When the measurement cannot run, WSL 2 means pass-through and elsewhere the product-name rules
 * apply. The measuring container is simulated (DistributionScripts: STUB_MEASURE_OWNER, STUB_MEASURE_SOCKET).
 */

const OWNER_IMAGE = 'ghcr.io/beyerenrico/fieldset@sha256:1111111111111111111111111111111111111111111111111111111111111111';

beforeEach(function (): void {
    $this->scripts = new DistributionScripts;
    mkdir($this->scripts->root.'/home');
    $this->dir = $this->scripts->root.'/instance';
    $this->uid = (string) posix_getuid();
    $this->gid = (string) posix_getgid();
    ownerInstance($this->dir);
    // The host-side group of the socket (native Linux only): GNU stat on the runner, whatever its system stat is.
    $this->scripts->writeExecutable('stat', "#!/bin/sh\nif [ \"\$*\" = '-L -c %g /var/run/docker.sock' ]; then echo \"\${STUB_HOST_SOCKET_GROUP:-998}\"; else exec /usr/bin/stat \"\$@\"; fi\n");
    $this->env = ['STUB_UNAME' => 'Linux', 'STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|host|[]'];
});

afterEach(fn () => $this->scripts->cleanUp());

/** An installed instance; with $owner = [container UID, runtime, socket group or null] its state records a measured owner (1.1). */
function ownerInstance(string $dir, ?array $owner = null): void
{
    if (! is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $state = InstanceState::create('3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $dir, posix_getuid(), posix_getgid(), $owner[0] ?? posix_getuid())
        ->withRelease('2.1.1', ['app' => OWNER_IMAGE]);
    if ($owner !== null) {
        $state = $state->withOwnerMeasurement($owner[1], $owner[2], CarbonImmutable::parse('2026-10-26T18:00:00Z'));
    }
    file_put_contents($dir.'/state.json', $state->toJson());
    copy(base_path('distribution/shim.sh'), $dir.'/fieldset');
    chmod($dir.'/fieldset', 0700);
}

/** @param  list<string>  $run  @return string|null the value after $flag in a docker run */
function ownerFlag(array $run, string $flag): ?string
{
    $index = array_search($flag, $run, true);

    return $index === false ? null : $run[$index + 1];
}

it('runs every runtime as the measured owner: user, owner UID and socket group (KTD2, KTD4; covers AE2)', function (array $env, string $measured, ?string $socket, bool $root, ?string $group, string $socketGroup): void {
    if (isset($env['wsl'])) {
        $this->scripts->wsl();
        unset($env['wsl']);
    }
    $measured = $measured === 'host' ? $this->uid : $measured;
    $process = $this->scripts->run($this->dir.'/fieldset', ['status'], [...$this->env, ...$env, 'STUB_MEASURE_OWNER' => $measured, 'STUB_MEASURE_SOCKET' => $socket ?? false]);
    $run = $this->scripts->opsRuns()[0];
    $facts = $this->scripts->opsEnvironment();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getErrorOutput())->toBe('')
        ->and($this->scripts->measureRuns())->toHaveCount(1)
        ->and(ownerFlag($run, '--user'))->toBe($root ? '0:0' : "{$this->uid}:{$this->gid}")
        ->and($facts)->toMatchArray(['FIELDSET_CONTAINER_UID' => $root ? '0' : $this->uid, 'FIELDSET_OWNER_SOURCE' => 'measured', 'FIELDSET_SOCKET_GROUP' => $socketGroup])
        ->and(ownerFlag($run, '--group-add'))->toBe($group)
        ->and(implode(' ', $run))->toContain('-e FIELDSET_OWNER_SOURCE ', '-e FIELDSET_SOCKET_GROUP ');
})->with([
    'macOS (Docker Desktop)' => [['STUB_UNAME' => 'Darwin', 'DOCKER_HOST' => 'unix:///Users/u/.docker/run/docker.sock'], '0', null, true, null, ''],
    'rootful Linux: the host-side socket group' => [[], 'host', '999 660', false, '998', ''],
    'rootless Linux' => [['STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|host|["name=rootless"]', 'XDG_RUNTIME_DIR' => '/run/user/1000'], '0', null, true, null, ''],
    'Docker Desktop on Linux' => [['STUB_DOCKER_INFO' => 'Docker Desktop|docker-desktop|[]'], '0', null, true, null, ''],
    'Colima' => [['STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|colima|[]'], '0', null, true, null, ''],
    'WSL 2 with Docker Desktop: root\'s socket inside containers' => [['wsl' => true, 'STUB_DOCKER_INFO' => 'Docker Desktop|docker-desktop|[]'], 'host', '0 660', false, '0', '0'],
    'Docker Engine in WSL 2: the in-container group' => [['wsl' => true, 'STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS (containerized)|PC|[]'], 'host', '999 660', false, '999', '999'],
    'rootless Docker Engine in WSL 2' => [['wsl' => true, 'STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|PC|["name=rootless"]', 'XDG_RUNTIME_DIR' => '/run/user/1000'], '0', null, true, null, ''],
]);

it('measures as container root of the state\'s image, reading the nonce file and the socket over read-only mounts', function (): void {
    $process = $this->scripts->run($this->dir.'/fieldset', ['status'], [...$this->env, 'STUB_MEASURE_OWNER' => $this->uid]);
    $measure = $this->scripts->measureRuns()[0];
    $nonce = $this->scripts->opsEnvironment()['FIELDSET_NONCE'];
    $runs = array_values(array_filter($this->scripts->calls('docker'), fn (array $call): bool => $call[1] === 'run'));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_slice($measure, 0, 7))->toBe(['run', '--rm', '--network', 'none', '--label', 'app.fieldset.role=owner', '--user'])
        ->and(ownerFlag($measure, '--user'))->toBe('0:0')
        ->and(array_slice($measure, array_search('--entrypoint', $measure, true)))->toBe([
            '--entrypoint', 'stat', '-v', '/var/run/docker.sock:/var/run/docker.sock:ro', '-v', "{$this->dir}:{$this->dir}:ro", OWNER_IMAGE,
            '-L', '-c', '%u %g %a', "{$this->dir}/{$nonce}", '/var/run/docker.sock',
        ])
        ->and($runs[0])->toBe(['docker', ...$measure])
        ->and(glob($this->dir.'/.fieldset-nonce-*'))->toBe([]);
});

it('measures only when it matters: always for install, doctor and repair-ownership, else without a saved owner or on another runtime (KTD3)', function (string $command, ?array $saved, array $env, bool $measures): void {
    ownerInstance($this->dir, $saved === null ? null : [$saved[0] === 'host' ? posix_getuid() : $saved[0], $saved[1], $saved[2]]);

    $process = $this->scripts->run($this->dir.'/fieldset', [$command, '--json'], [...$this->env, ...$env, 'STUB_MEASURE_OWNER' => $this->uid]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($this->scripts->measureRuns())->toHaveCount($measures ? 1 : 0)
        ->and($this->scripts->opsEnvironment()['FIELDSET_OWNER_SOURCE'])->toBe($measures ? 'measured' : 'saved');
})->with([
    'status, state from 2.1.0 (no saved owner)' => ['status', null, [], true],
    'status, saved on the same runtime' => ['status', ['host', DockerRuntime::Rootful, null], [], false],
    'logs, saved on the same runtime' => ['logs', ['host', DockerRuntime::Rootful, null], [], false],
    'status, saved under rootful, now rootless' => ['status', ['host', DockerRuntime::Rootful, null], ['STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|host|["name=rootless"]'], true],
    'install, saved on the same runtime' => ['install', ['host', DockerRuntime::Rootful, null], [], true],
    'doctor, saved on the same runtime' => ['doctor', ['host', DockerRuntime::Rootful, null], [], true],
    'repair-ownership, saved on the same runtime' => ['repair-ownership', ['host', DockerRuntime::Rootful, null], [], true],
]);

it('runs a command as the saved owner with its saved in-container socket group on WSL 2 (KTD3)', function (): void {
    $this->scripts->wsl();
    ownerInstance($this->dir, [posix_getuid(), DockerRuntime::Desktop, 0]);

    $process = $this->scripts->run($this->dir.'/fieldset', ['status'], [...$this->env, 'STUB_DOCKER_INFO' => 'Docker Desktop|docker-desktop|[]']);
    $run = $this->scripts->opsRuns()[0];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($this->scripts->measureRuns())->toBe([])
        ->and(ownerFlag($run, '--user'))->toBe("{$this->uid}:{$this->gid}")
        ->and(ownerFlag($run, '--group-add'))->toBe('0')
        ->and($this->scripts->opsEnvironment())->toMatchArray(['FIELDSET_CONTAINER_UID' => $this->uid, 'FIELDSET_OWNER_SOURCE' => 'saved', 'FIELDSET_SOCKET_GROUP' => '0']);
});

it('runs as root when the saved owner is container root, and measures when the saved owner is out of grammar', function (string $edit, bool $measures): void {
    ownerInstance($this->dir, [0, DockerRuntime::Desktop, null]);
    $json = (string) file_get_contents($this->dir.'/state.json');
    file_put_contents($this->dir.'/state.json', $edit === '' ? $json : str_replace('"runtime": "desktop"', $edit, $json));

    $process = $this->scripts->run($this->dir.'/fieldset', ['status'], [...$this->env, 'STUB_DOCKER_INFO' => 'Docker Desktop|docker-desktop|[]', 'STUB_MEASURE_OWNER' => '0']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($this->scripts->measureRuns())->toHaveCount($measures ? 1 : 0)
        ->and(ownerFlag($this->scripts->opsRuns()[0], '--user'))->toBe('0:0')
        ->and($this->scripts->opsEnvironment()['FIELDSET_CONTAINER_UID'])->toBe('0');
})->with([
    'saved container root' => ['', false],
    'unknown runtime' => ['"runtime": "podman"', true],
    'runtime with a payload' => ['"runtime": "desktop --privileged"', true],
]);

it('stops when containers see the host user\'s files as another UID, naming both (R27)', function (string $command): void {
    $process = $this->scripts->run($this->dir.'/fieldset', [$command, '--json'], [...$this->env, 'STUB_MEASURE_OWNER' => '65534']);
    $error = json_decode($process->getOutput(), true)['errors'][0] ?? [];

    expect($process->getExitCode())->toBe(3, $process->getErrorOutput())
        ->and($error['code'] ?? null)->toBe('owner_mapping_unknown')
        ->and($error['message'])->toContain("UID {$this->uid}", 'UID 65534')
        ->and($error['hint'])->toContain('userns-remap')
        ->and($this->scripts->opsRuns())->toBe([])
        ->and(glob($this->dir.'/.fieldset-nonce-*'))->toBe([]);
})->with(['status', 'doctor']);

it('stops with the socket hint when the socket grants its group no access, and runs when others may write it (KTD4)', function (string $socket, bool $stops): void {
    $this->scripts->wsl();

    $process = $this->scripts->run($this->dir.'/fieldset', ['status', '--json'], [...$this->env, 'STUB_DOCKER_INFO' => 'Docker Desktop|docker-desktop|[]', 'STUB_MEASURE_OWNER' => $this->uid, 'STUB_MEASURE_SOCKET' => $socket]);
    $error = json_decode($process->getOutput(), true)['errors'][0] ?? [];

    expect($process->getExitCode())->toBe($stops ? 3 : 0, $process->getErrorOutput())
        ->and($this->scripts->opsRuns())->toHaveCount($stops ? 0 : 1);
    if ($stops) {
        expect($error['code'])->toBe('socket_no_group_access')
            ->and($error['message'])->toContain('mode '.substr($socket, 2), "UID {$this->uid}")
            ->and($error['hint'])->toContain('mode 660');
    }
})->with([
    'owner only (600)' => ['0 600', true],
    'group may only read (640)' => ['0 640', true],
    'group may write (660)' => ['0 660', false],
    'others may write (666)' => ['0 666', false],
]);

it('falls back when the measuring container cannot run: pass-through on WSL 2 and says so, the product-name rules elsewhere (KTD3)', function (array $env, bool $wsl, bool $root, bool $warns): void {
    if ($wsl) {
        $this->scripts->wsl();
    }

    $process = $this->scripts->run($this->dir.'/fieldset', ['status', '--json'], [...$this->env, ...$env, 'STUB_MEASURE_STDERR' => 'docker: Error response from daemon: mounts denied']);
    $events = array_filter(array_map(fn (string $line): mixed => json_decode($line, true), explode("\n", trim($process->getErrorOutput()))));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(ownerFlag($this->scripts->opsRuns()[0], '--user'))->toBe($root ? '0:0' : "{$this->uid}:{$this->gid}")
        ->and($this->scripts->opsEnvironment())->toMatchArray(['FIELDSET_CONTAINER_UID' => $root ? '0' : $this->uid, 'FIELDSET_OWNER_SOURCE' => 'rules'])
        ->and($events)->toHaveCount($warns ? 1 : 0);
    if ($warns) {
        expect(array_values($events)[0])->toMatchArray(['phase' => 'launcher', 'level' => 'warn'])
            ->and(array_values($events)[0]['message'])->toContain('Could not measure', 'mounts denied', "WSL 2 passes user IDs through, so fieldset runs as UID {$this->uid}");
    }
})->with([
    'WSL 2 with Docker Desktop' => [['STUB_DOCKER_INFO' => 'Docker Desktop|docker-desktop|[]'], true, false, true],
    'Docker Engine in WSL 2' => [['STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|PC|[]'], true, false, true],
    'rootless Docker Engine in WSL 2: container root is the host user' => [['STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|PC|["name=rootless"]'], true, true, false],
    'rootful Linux' => [[], false, false, false],
    'Docker Desktop on Linux' => [['STUB_DOCKER_INFO' => 'Docker Desktop|docker-desktop|[]'], false, true, false],
    'macOS' => [['STUB_UNAME' => 'Darwin'], false, true, false],
]);
