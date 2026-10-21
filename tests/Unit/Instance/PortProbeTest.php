<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\NetworkEndpoint;
use App\Domain\Instance\Docker\NetworkInfo;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Doctor\Checks\EdgePorts;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\Doctor\PortProbe;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use Tests\Support\Instance\FakeDoctorProbes;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\RecordingDockerGateway;
use Tests\Support\Instance\TemporaryInstance;

/*
 * M10 gate (ADR 0078): port probes. "Permission denied" means rootless Docker refusing a privileged port only in
 * RootlessKit's own words or for a host-wide probe below 1024; any other refusal is a failed probe, never a hint to
 * change a sysctl. The edge's ports check also probes [::] on a host with IPv6, and an engine without IPv6 leaves it
 * unprobed rather than failing. An install on a shared proxy network needs no free loopback app port.
 */

beforeEach(function (): void {
    $this->docker = new RecordingDockerGateway;
    $this->probe = new PortProbe($this->docker);
});

afterEach(fn () => TemporaryInstance::cleanup());

/** @param  list<string>  $lan */
function edgeContext(RecordingDockerGateway $docker, array $lan): DoctorContext
{
    return new DoctorContext(new HostFacts(lanAddresses: $lan), TemporaryInstance::directory(), $docker, new FakeDoctorProbes, null, 'forms.example.com', 'app-image', uuid: 'u-1');
}

it('reads only RootlessKit wording or a host-wide refusal below 1024 as privileged ports', function (string $stderr, bool $hostWide, int $port, string $error): void {
    $this->docker->queueOutcome('runContainer', new ProcessOutcome(125, '', $stderr));

    try {
        $hostWide ? $this->probe->isFreeOnHost($port, 'img') : $this->probe->isFree($port, 'img');
        $this->fail('The probe must fail.');
    } catch (InstanceException $e) {
        expect($e->errorCode)->toBe($error);
    }
})->with([
    'loopback, permission denied on the image store' => ['docker: open /var/lib/docker/tmp/x: permission denied', false, 8080, 'port_probe_failed'],
    'loopback, permission denied while binding' => ['listen tcp4 127.0.0.1:8080: bind: permission denied', false, 8080, 'port_probe_failed'],
    'host-wide 443, bare permission denied' => ['listen tcp4 0.0.0.0:443: bind: permission denied', true, 443, 'privileged_ports'],
    'host-wide 8443, bare permission denied' => ['listen tcp4 0.0.0.0:8443: bind: permission denied', true, 8443, 'port_probe_failed'],
    'RootlessKit wording' => ['cannot expose privileged port 80, you can add \'net.ipv4.ip_unprivileged_port_start=80\'', true, 80, 'privileged_ports'],
]);

it('probes [::] as well when the host has an IPv6 address, and reports a port taken there', function (): void {
    $this->docker->respondToRun(fn (ContainerSpec $spec): ProcessOutcome => $spec->ports[0] === '[::]:443:8080'
        ? new ProcessOutcome(125, '', 'Bind for [::]:443 failed: port is already allocated')
        : new ProcessOutcome(0));

    $result = new EdgePorts($this->probe)->check(edgeContext($this->docker, ['192.168.1.20', '2001:db8::5']), 'app-image', 'ports');

    $bindings = array_map(fn (array $call): string => $call['spec']->ports[0], $this->docker->called('runContainer'));
    expect($bindings)->toBe(['0.0.0.0:80:8080', '[::]:80:8080', '0.0.0.0:443:8080', '[::]:443:8080'])
        ->and($result->status->value)->toBe('fail')
        ->and($result->details)->toMatchArray(['taken' => [443], 'ipv6' => true]);
});

it('probes only 0.0.0.0 without IPv6, and leaves IPv6 unprobed when the engine cannot bind it', function (): void {
    $this->docker->respondToRun(fn (ContainerSpec $spec): ProcessOutcome => str_starts_with($spec->ports[0], '[::]')
        ? new ProcessOutcome(125, '', 'listen tcp6 [::]:80: socket: address family not supported by protocol')
        : new ProcessOutcome(0));

    $v4 = new EdgePorts($this->probe)->check(edgeContext($this->docker, ['192.168.1.20', '::1']), 'app-image', 'ports');
    $v4Bindings = count($this->docker->called('runContainer'));
    $off = new EdgePorts($this->probe)->check(edgeContext($this->docker, ['2001:db8::5']), 'app-image', 'ports');

    expect($v4->status->value)->toBe('ok')
        ->and($v4Bindings)->toBe(2)
        ->and($v4->details['ipv6'])->toBeFalse()
        ->and($off->status->value)->toBe('ok')
        ->and($off->details['ipv6'])->toBeFalse();
});

it('installs on a shared proxy network although every loopback app port is taken', function (): void {
    $fixture = new InstanceCommandFixture;
    $fixture->takenPorts = range(8080, 8099);
    $id = str_repeat('7', 64);
    $fixture->docker->containers[$id] = new ContainerInfo($id, 'traefik-1', 'running', null, null, 'traefik:v3.7.15');
    $fixture->docker->withNetwork(new NetworkInfo('net0', 'edge-net', false, ['172.20.0.0/16'], ['172.20.0.1'], [new NetworkEndpoint($id, 'traefik-1', '172.20.0.5')]));

    [$code, $json] = $fixture->run('fieldset:install', InstanceCommandFixture::installFlags(['--behind-proxy' => 'traefik', '--proxy-network' => 'edge-net', '--url' => 'https://forms.example.com']));

    $probed = array_map(fn (ContainerSpec $spec): string => $spec->ports[0], $fixture->runSpecs('true'));
    expect($code)->toBe(0, json_encode($json))
        ->and($probed)->toBe(['127.0.0.1:8025:8080'])
        ->and($fixture->state()->setting('mail_ui_port'))->toBe(8025);
});
