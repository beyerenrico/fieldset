<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\NetworkEndpoint;
use App\Domain\Instance\Docker\NetworkInfo;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * M10 gate (ADR 0078, closes the gap ADR 0076/0077 left): proxy mode trusts one address. `fieldset doctor` re-resolves
 * the recorded proxy container (the gateway in the `port` setup) and warns `proxy_address_changed` when the address
 * moved; `fieldset status` reports the proxy, the trusted address and the current one.
 */

afterEach(fn () => TemporaryInstance::cleanup());

const DRIFT_TRAEFIK_ID = '7777777777777777777777777777777777777777777777777777777777777777';

function driftNetwork(string $address): NetworkInfo
{
    return new NetworkInfo('net0', 'edge-net', false, ['172.20.0.0/16'], ['172.20.0.1'], $address === '' ? [] : [new NetworkEndpoint(DRIFT_TRAEFIK_ID, 'traefik-1', $address)]);
}

function driftTraefik(): ConfigFixture
{
    $config = new ConfigFixture(['--behind-proxy' => 'traefik', '--proxy-network' => 'edge-net', '--url' => 'https://forms.example.com'], function (InstanceCommandFixture $fixture): void {
        $fixture->docker->containers[DRIFT_TRAEFIK_ID] = new ContainerInfo(DRIFT_TRAEFIK_ID, 'traefik-1', 'running', null, null, 'traefik:v3.7.15');
        $fixture->docker->withNetwork(driftNetwork('172.20.0.5'));
    });
    $config->instance->withRunningStack();

    return $config;
}

it('passes the proxy check while the recorded proxy still holds the trusted address', function (): void {
    $config = driftTraefik();

    [$code, $json] = $config->instance->run('fieldset:doctor');
    [, $status] = $config->instance->run('fieldset:status');

    $check = collect($json['data']['checks'])->firstWhere('key', 'proxy');
    expect($code)->toBe(0, json_encode($json))
        ->and($check['status'])->toBe('ok')
        ->and($check['details'])->toMatchArray(['container' => 'traefik-1', 'trusted' => ['172.20.0.5'], 'current' => '172.20.0.5'])
        ->and($status['data']['proxy'])->toMatchArray(['setup' => 'traefik', 'container' => 'traefik-1', 'trusted' => ['172.20.0.5'], 'current' => '172.20.0.5', 'moved' => false])
        ->and(collect($status['warnings'])->pluck('code')->all())->not->toContain('proxy_address_changed');
});

it('warns proxy_address_changed in doctor and status when a recreated proxy got another address', function (): void {
    $config = driftTraefik();
    $config->instance->docker->withNetwork(driftNetwork('172.20.0.9'));

    [$code, $json] = $config->instance->run('fieldset:doctor');
    [, $status] = $config->instance->run('fieldset:status');

    $warning = collect($json['warnings'])->firstWhere('code', 'proxy_address_changed');
    expect($code)->toBe(0, json_encode($json))
        ->and(collect($json['data']['checks'])->firstWhere('key', 'proxy')['status'])->toBe('warn')
        ->and($warning['message'])->toContain('172.20.0.5')->toContain('172.20.0.9')
        ->and($warning['hint'])->toContain('fieldset config set trusted-proxy auto')
        ->and($status['data']['proxy'])->toMatchArray(['current' => '172.20.0.9', 'moved' => true])
        ->and(collect($status['warnings'])->firstWhere('code', 'proxy_address_changed')['hint'])->toContain('fieldset config set trusted-proxy auto');

    $config->set('trusted-proxy', 'auto');
    [, $after] = $config->instance->run('fieldset:doctor');
    expect(collect($after['warnings'])->pluck('code')->all())->not->toContain('proxy_address_changed');
});

it('warns when the recorded proxy left the network', function (): void {
    $config = driftTraefik();
    $config->instance->docker->withNetwork(driftNetwork(''));

    [, $json] = $config->instance->run('fieldset:doctor');

    expect(collect($json['warnings'])->firstWhere('code', 'proxy_address_changed')['message'])->toContain('not attached to edge-net');
});

it('compares the gateway in the port setup, and skips the check outside proxy mode', function (): void {
    $config = new ConfigFixture(['--behind-proxy' => 'port', '--url' => 'https://forms.example.com'], function (InstanceCommandFixture $fixture): void {
        $fixture->docker->networkResponder = fn (string $name): ?NetworkInfo => str_ends_with($name, '_default') ? new NetworkInfo('n1', $name, false, ['172.30.0.0/16'], ['172.30.0.1']) : null;
    });
    $config->instance->withRunningStack();
    $config->instance->docker->networkResponder = fn (string $name): ?NetworkInfo => str_ends_with($name, '_default') ? new NetworkInfo('n1', $name, false, ['172.31.0.0/16'], ['172.31.0.1']) : null;

    [, $port] = $config->instance->run('fieldset:doctor');
    $local = new ConfigFixture;
    [, $localDoctor] = $local->instance->run('fieldset:doctor');
    [, $localStatus] = $local->instance->run('fieldset:status');

    expect(collect($port['warnings'])->firstWhere('code', 'proxy_address_changed')['message'])->toContain('gateway is now 172.31.0.1')
        ->and(collect($localDoctor['data']['checks'])->firstWhere('key', 'proxy')['status'])->toBe('skipped')
        ->and($localStatus['data']['proxy'])->toBeNull();
});
