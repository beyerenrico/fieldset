<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerInfo;
use App\Domain\Instance\Docker\DockerRuntime;
use App\Domain\Instance\Docker\DockerUnavailable;
use App\Domain\Instance\Docker\DockerVersions;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\State\InstanceState;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * U5 / M9-T08: `fsa doctor` (R10, KTD2, ADR 0070, ADR 0072) with scripted Docker and probes: each check's pass,
 * warn and fail, exit 3 for a failed preflight (10 for a too old Docker), and the ownership rule per runtime.
 */

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
});

afterEach(fn () => TemporaryInstance::cleanup());

/** @return array<string, array<string, mixed>> checks by key */
function doctorChecks(array $json): array
{
    return collect($json['data']['checks'] ?? [])->keyBy('key')->all();
}

function doctorState(InstanceCommandFixture $fixture, array $settings = [], string $mode = 'local', ?int $containerUid = null): InstanceState
{
    $state = InstanceState::create('3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $fixture->directory->root, posix_getuid(), posix_getgid(), $containerUid ?? posix_getuid(), $mode)
        ->withRelease('2.0.0-alpha.1', ['app' => InstanceCommandFixture::appImage()])
        ->withSettings($settings);
    $fixture->directory->saveState($state);

    return $state;
}

it('passes on a healthy host and reports every check in order', function (): void {
    doctorState($this->fixture);

    [$code, $json] = $this->fixture->run('fsa:doctor');

    expect($code)->toBe(0, json_encode($json))
        ->and(array_column($json['data']['checks'], 'key'))->toBe(['docker', 'architecture', 'memory', 'disk', 'path_sharing', 'permissions', 'stale_files', 'dns', 'registry', 'clock', 'ports'])
        ->and(doctorChecks($json)['dns']['status'])->toBe('skipped')
        ->and(doctorChecks($json)['path_sharing']['status'])->toBe('skipped')
        ->and(doctorChecks($json)['registry']['summary'])->toBe('Every image is already on this host.')
        ->and(doctorChecks($json)['ports']['details']['ports'])->toBe(['app' => 8080, 'mail_ui' => 8025]);
});

it('fails with exit 3 and a permission hint when the shim is group-writable (KTD2)', function (): void {
    doctorState($this->fixture);
    $shim = $this->fixture->directory->root.'/fsa';
    file_put_contents($shim, "#!/bin/sh\n");
    chmod($shim, 0770);

    [$code, $json] = $this->fixture->run('fsa:doctor');

    expect($code)->toBe(3)
        ->and($json['errors'][0]['code'])->toBe('doctor_failed')
        ->and(doctorChecks($json)['permissions']['status'])->toBe('fail')
        ->and(doctorChecks($json)['permissions']['summary'])->toContain('fsa command is writable by group or others (mode 0770)')
        ->and($json['errors'][0]['hint'])->toContain('chmod go-w '.$shim);
});

it('checks ownership against the in-container owner UID of each runtime', function (DockerRuntime $runtime, int $fileOwner, bool $passes): void {
    $hostUid = 1000;
    $this->fixture->facts(['uid' => $hostUid, 'runtime' => $runtime, 'containerUid' => null]);
    $this->fixture->probes->stats[$this->fixture->directory->root] = ['uid' => $fileOwner, 'mode' => 0700];

    [, $json] = $this->fixture->run('fsa:doctor');

    expect(doctorChecks($json)['permissions']['status'])->toBe($passes ? 'ok' : 'fail');
})->with([
    'rootful: files of the host user' => [DockerRuntime::Rootful, 1000, true],
    'rootful: files of root' => [DockerRuntime::Rootful, 0, false],
    'rootless: files of container root' => [DockerRuntime::Rootless, 0, true],
    'desktop: files of container root' => [DockerRuntime::Desktop, 0, true],
    'desktop: files of another UID' => [DockerRuntime::Desktop, 1000, false],
]);

it('exits 10 when Docker Engine is too old', function (): void {
    $this->fixture->docker->versions = new DockerVersions('29.9.0', '20.10.24', '5.6.0');

    [$code, $json] = $this->fixture->run('fsa:doctor');

    expect($code)->toBe(10)
        ->and($json['errors'][0]['code'])->toBe('docker_too_old')
        ->and(doctorChecks($json)['docker']['summary'])->toContain('20.10.24');
});

it('fails with exit 3, not "too old", when the Docker versions cannot be read', function (): void {
    $this->fixture->docker->failOn('versions', new InstanceException('Unexpected output from `docker version`.', errorCode: 'docker_output_invalid'));

    [$code, $json] = $this->fixture->run('fsa:doctor');

    expect($code)->toBe(3)->and($json['errors'][0]['code'])->toBe('doctor_failed');
});

it('exits 10 when the Docker engine is unreachable', function (): void {
    $this->fixture->docker->failOn('info', new DockerUnavailable('The Docker daemon is not reachable.'));

    [$code, $json] = $this->fixture->run('fsa:doctor');

    expect($code)->toBe(10)->and($json['errors'][0]['code'])->toBe('docker_unavailable');
});

it('fails below the memory floor, warns close to it and fails an unsupported architecture', function (int $mib, string $arch, string $key, string $status): void {
    $this->fixture->docker->info = new DockerInfo('29.9.0', 'Ubuntu', $arch, $mib * 1024 * 1024, 2);

    [$code, $json] = $this->fixture->run('fsa:doctor');

    expect(doctorChecks($json)[$key]['status'])->toBe($status)
        ->and($code)->toBe($status === 'fail' ? 3 : 0);
})->with([
    'tiny host' => [1024, 'x86_64', 'memory', 'fail'],
    'just 2 GB' => [1700, 'aarch64', 'memory', 'warn'],
    'unknown size' => [0, 'x86_64', 'memory', 'warn'],
    's390x' => [4096, 's390x', 'architecture', 'fail'],
]);

it('fails on a nearly full disk, warns on a small one and when it cannot tell', function (?float $gib, string $status): void {
    $this->fixture->probes->free = $gib === null ? null : $gib * 1024 ** 3;

    [, $json] = $this->fixture->run('fsa:doctor');

    expect(doctorChecks($json)['disk']['status'])->toBe($status);
})->with([[2.0, 'fail'], [8.0, 'warn'], [null, 'warn']]);

it('confirms path sharing when started by a launcher whose nonce it saw', function (): void {
    $nonce = '.fsa-nonce-0123456789abcdef0123456789abcdef';
    touch($this->fixture->directory->root.'/'.$nonce);
    $this->fixture->facts(['nonce' => $nonce]);

    [, $json] = $this->fixture->run('fsa:doctor');

    expect(doctorChecks($json)['path_sharing']['status'])->toBe('ok');
});

it('removes stale nonce and hand-over files of interrupted runs, keeping its own and recent ones', function (): void {
    $root = $this->fixture->directory->root;
    $own = '.fsa-nonce-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $files = [$own => 7200, '.fsa-nonce-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' => 7200, '.fsa-open-url-cccccccccccccccccccccccccccccccc' => 7200, '.fsa-nonce-dddddddddddddddddddddddddddddddd' => 60];
    foreach ($files as $name => $age) {
        touch($root.'/'.$name, time() - $age);
    }
    $this->fixture->facts(['nonce' => $own]);

    [, $json] = $this->fixture->run('fsa:doctor');

    expect(doctorChecks($json)['stale_files']['details']['removed'])->toBe(['.fsa-nonce-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', '.fsa-open-url-cccccccccccccccccccccccccccccccc'])
        ->and(file_exists($root.'/'.$own))->toBeTrue()
        ->and(file_exists($root.'/.fsa-nonce-dddddddddddddddddddddddddddddddd'))->toBeTrue();
});

it('checks A and AAAA records of the domain separately (domain mode)', function (array $a, array $aaaa, array $host, string $status, string $text): void {
    doctorState($this->fixture, ['domain' => 'forms.example.com'], 'domain');
    $this->fixture->probes->dns['forms.example.com'] = ['a' => $a, 'aaaa' => $aaaa];
    $this->fixture->facts(['lanAddresses' => $host]);

    [, $json] = $this->fixture->run('fsa:doctor');
    $dns = doctorChecks($json)['dns'];

    expect($dns['status'])->toBe($status)->and($dns['summary'])->toContain($text);
})->with([
    'resolves here' => [['8.8.4.4'], [], ['8.8.4.4'], 'ok', 'resolves to this host'],
    'no records' => [[], [], ['8.8.4.4'], 'fail', 'does not resolve'],
    'elsewhere, host is public' => [['1.1.1.1'], [], ['8.8.4.4'], 'fail', 'but this host'],
    'non-public address' => [['10.9.8.7'], [], ['192.168.1.5'], 'fail', 'not an address of this host'],
    'behind NAT' => [['1.1.1.1'], [], ['192.168.1.5'], 'warn', 'NAT'],
    'only AAAA' => [[], ['2001:4860:4860::8888'], ['8.8.4.4'], 'warn', 'no A record'],
    'public AAAA' => [['8.8.4.4'], ['2001:4860:4860::8888'], ['8.8.4.4'], 'warn', 'AAAA'],
    'private AAAA' => [['8.8.4.4'], ['fd00::1'], ['8.8.4.4'], 'fail', 'not public'],
]);

it('reaches the registries of missing images, reads the clock from them and skips loopback mirrors', function (?int $offset, string $clock): void {
    // The Date header is built when the test runs: a dataset value is built when the file loads, and a long
    // single-process run (coverage) can start this test minutes later.
    $date = $offset === null ? null : gmdate('D, d M Y H:i:s \G\M\T', time() + $offset);
    doctorState($this->fixture);
    $this->fixture->docker->images = [];
    $this->fixture->probes->registry = ['status' => 401, 'date' => $date];
    $state = $this->fixture->state()->withRelease('2.0.0-alpha.1', [
        'app' => 'ghcr.io/beyerenrico/form-schema-app@sha256:'.str_repeat('a', 64),
        'postgres' => '127.0.0.1:5000/library/postgres@sha256:'.str_repeat('b', 64),
    ]);
    $this->fixture->directory->saveState($state);

    [, $json] = $this->fixture->run('fsa:doctor');

    expect(doctorChecks($json)['registry']['details']['reachable'])->toBe(['ghcr.io'])
        ->and($this->fixture->probes->heads)->toBe(['https://ghcr.io/v2/'])
        ->and(doctorChecks($json)['clock']['status'])->toBe($clock);
})->with([
    'in sync' => [0, 'ok'],
    'two minutes off' => [-120, 'warn'],
    'an hour off' => [3600, 'fail'],
    'no Date header' => [null, 'skipped'],
]);

it('warns when a registry cannot be reached', function (): void {
    $this->fixture->docker->images = [];
    $this->fixture->probes->registry = null;
    $state = InstanceState::create('3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $this->fixture->directory->root, posix_getuid(), posix_getgid(), posix_getuid())
        ->withRelease('2.0.0-alpha.1', ['app' => 'docker.io/library/app@sha256:'.str_repeat('a', 64)]);
    $this->fixture->directory->saveState($state);

    [$code, $json] = $this->fixture->run('fsa:doctor');

    expect($code)->toBe(0)
        ->and(doctorChecks($json)['registry']['status'])->toBe('warn')
        ->and($this->fixture->probes->heads)->toBe(['https://registry-1.docker.io/v2/'])
        ->and(collect($json['warnings'])->pluck('code'))->toContain('doctor_registry');
});

it('reports the ports of an installed instance as its own while it runs, and taken ones otherwise', function (): void {
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    $this->fixture->withRunningStack();

    [$ownCode, $own] = $this->fixture->run('fsa:doctor', ['--post' => true]);
    foreach (array_keys($this->fixture->docker->containers) as $id) {
        $this->fixture->docker->stopContainer((string) $id);
    }
    $this->fixture->takenPorts = [8080];
    [$takenCode, $taken] = $this->fixture->run('fsa:doctor');

    expect($ownCode)->toBe(0, json_encode($own))
        ->and(doctorChecks($own)['ports']['summary'])->toContain('served by this instance')
        ->and(doctorChecks($own)['services']['status'])->toBe('ok')
        ->and($takenCode)->toBe(3)
        ->and(doctorChecks($taken)['ports']['summary'])->toContain('in use by another program');
});

it('treats the ports of its own running containers as served and probes only the others', function (): void {
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    $this->fixture->withRunningStack();
    $this->fixture->docker->stopContainer(str_repeat('1', 12)); // app stopped, mailpit (8025) still runs
    $this->fixture->takenPorts = [8025];
    $probes = count($this->fixture->runSpecs('true'));

    [$code, $json] = $this->fixture->run('fsa:doctor');
    $ports = doctorChecks($json)['ports'];
    $probed = array_map(static fn ($spec): string => $spec->ports[0], array_slice($this->fixture->runSpecs('true'), $probes));

    expect($code)->toBe(0, json_encode($json))
        ->and($ports['status'])->toBe('ok')
        ->and($ports['summary'])->toBe('Port(s) 8025 served by this instance, 8080 free.')
        ->and($ports['details'])->toBe(['ports' => ['app' => 8080, 'mail_ui' => 8025], 'taken' => []])
        ->and($probed)->toBe(['127.0.0.1:8080:8080']);
});

it('probes every port when the project\'s containers cannot be listed', function (): void {
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    $this->fixture->docker->failOn('composePs', new DockerCommandFailed('compose ps', new ProcessOutcome(1, '', 'no such file')));
    $this->fixture->takenPorts = [8025];

    [$code, $json] = $this->fixture->run('fsa:doctor');

    expect($code)->toBe(3)
        ->and(doctorChecks($json)['ports']['details'])->toBe(['ports' => ['app' => 8080, 'mail_ui' => 8025], 'taken' => [8025]]);
});

it('uses one details shape for the ports check when selecting and when verifying', function (): void {
    $this->fixture->takenPorts = [9000];
    [, $selecting] = $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags(['--port' => '9000']));
    doctorState($this->fixture);
    [, $verifying] = $this->fixture->run('fsa:doctor');

    expect(doctorChecks($selecting)['ports']['details'])->toBe(['ports' => [], 'taken' => [9000]])
        ->and(array_keys(doctorChecks($verifying)['ports']['details']))->toBe(['ports', 'taken']);
});

it('skips the port check before an image is pinned, and fails --post for missing services', function (): void {
    $this->fixture->takenPorts = [8080];
    [$beforeCode, $before] = $this->fixture->run('fsa:doctor');
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    [$postCode, $post] = $this->fixture->run('fsa:doctor', ['--post' => true]);

    expect($beforeCode)->toBe(0)
        ->and(doctorChecks($before)['ports']['status'])->toBe('skipped')
        ->and($postCode)->toBe(3)
        ->and(doctorChecks($post)['services']['summary'])->toContain('pp is not created; queue is not created');
});

it('fails the ports check when a probe cannot run', function (): void {
    doctorState($this->fixture);
    $this->fixture->docker->respondToRun(fn () => new ProcessOutcome(125, '', 'docker: Error response from daemon: no such image'));

    [$code, $json] = $this->fixture->run('fsa:doctor');

    expect($code)->toBe(3)
        ->and(doctorChecks($json)['ports']['summary'])->toContain('Cannot probe port 8080')
        ->and(doctorChecks($json)['ports']['hint'])->toContain('app image');
});

it('warns about a taken port of an instance that is not installed yet', function (): void {
    doctorState($this->fixture);
    $this->fixture->takenPorts = [8025];

    [$code, $json] = $this->fixture->run('fsa:doctor');

    expect($code)->toBe(0)
        ->and(doctorChecks($json)['ports']['status'])->toBe('warn')
        ->and(doctorChecks($json)['ports']['details']['taken'])->toBe([8025]);
});

it('fails --post without services', function (): void {
    doctorState($this->fixture);

    [$code, $json] = $this->fixture->run('fsa:doctor', ['--post' => true]);

    expect($code)->toBe(3)->and(doctorChecks($json)['services']['summary'])->toContain('no services');
});

it('fails when no loopback port near the default is free, and when Compose is too old', function (): void {
    $this->fixture->takenPorts = range(8080, 8099);
    [$code, $json] = $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    $this->fixture->docker->versions = new DockerVersions('29.9.0', '29.9.0', '2.20.0');
    [$composeCode, $compose] = $this->fixture->run('fsa:doctor');

    expect($code)->toBe(3)
        ->and(doctorChecks($json)['ports']['summary'])->toContain('No free loopback port found (tried 8080')
        ->and($composeCode)->toBe(10)
        ->and(doctorChecks($compose)['docker']['summary'])->toContain('Compose 2.20.0');
});
