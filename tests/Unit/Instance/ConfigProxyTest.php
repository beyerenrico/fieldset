<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\NetworkEndpoint;
use App\Domain\Instance\Docker\NetworkInfo;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\State\EnvFiles;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U11 (R17, R32, KTD10; ADR 0076, ADR 0077): `fsa config set url` and `trusted-proxy` in proxy mode, and the
 * proxy-mode side of `port` and `mode local`. The trusted proxy is re-resolved through the engine (never DNS), so a
 * recreated Traefik with a new address is followed with `auto`.
 */

afterEach(fn () => TemporaryInstance::cleanup());

/**
 * Traefik and a neighbour on the shared network `edge-net`.
 *
 * @param  array<string, array{0: string, 1: string}>  $containers  name => [image, address]
 */
function configSharedNetwork(InstanceCommandFixture $fixture, array $containers = ['traefik-1' => ['traefik:v3.7.15', '172.20.0.5'], 'neighbour-1' => ['nginx:1.29', '172.20.0.6']]): void
{
    $endpoints = [];
    foreach ($containers as $name => [$image, $address]) {
        $id = hash('sha256', $name);
        $fixture->docker->containers[$id] = new ContainerInfo($id, $name, 'running', null, null, $image);
        $endpoints[] = new NetworkEndpoint($id, $name, $address);
    }
    $fixture->docker->withNetwork(new NetworkInfo('net0', 'edge-net', false, ['172.20.0.0/16'], ['172.20.0.1'], $endpoints));
}

function configTraefik(): ConfigFixture
{
    return new ConfigFixture(['--behind-proxy' => 'traefik', '--proxy-network' => 'edge-net', '--url' => 'https://forms.example.com'], fn (InstanceCommandFixture $fixture) => configSharedNetwork($fixture));
}

function configPortProxy(): ConfigFixture
{
    return new ConfigFixture(['--behind-proxy' => 'port', '--url' => 'https://forms.example.com'], function (InstanceCommandFixture $fixture): void {
        $fixture->docker->networkResponder = fn (string $name): ?NetworkInfo => str_ends_with($name, '_default') ? new NetworkInfo('n1', $name, false, ['172.30.0.0/16'], ['172.30.0.1']) : null;
    });
}

it('changes the public URL in proxy mode: APP_URL, runtime, Traefik labels, and asks the app with the new host', function (): void {
    $config = configTraefik();

    [$code, $json] = $config->set('url', 'HTTPS://Forms2.Example.com/', ['--yes' => true]);

    $labels = ComposeFile::document($config->instance->directory)['services']['app']['labels'];
    $verify = array_values(array_filter($config->instance->runSpecs('curl'), fn (ContainerSpec $spec): bool => in_array('Host: forms2.example.com', $spec->command, true)));
    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['key' => 'url', 'from' => 'https://forms.example.com', 'to' => 'https://forms2.example.com', 'url' => 'https://forms2.example.com', 'mode' => 'proxy'])
        ->and($json['data']['services']['recreated'])->toBe(['app', 'queue', 'scheduler'])
        ->and($json['data']['checked'])->toContain('Host: forms2.example.com')
        ->and(implode(' ', $labels))->toContain('Host(`forms2.example.com`)')
        ->and(new EnvFiles($config->instance->directory)->read('app')['APP_URL'])->toBe('https://forms2.example.com')
        ->and($verify)->toHaveCount(1)
        ->and($verify[0]->labels['app.fieldset.role'])->toBe('config-verify');
});

it('refuses url outside proxy mode and malformed origins with exit 2', function (): void {
    $local = new ConfigFixture;

    [$code, $json] = $local->set('url', 'https://forms.example.com', ['--yes' => true]);
    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('not_in_this_mode')->and($json['errors'][0]['hint'])->toContain('config set port');

    $proxy = configTraefik();
    foreach (['https://forms.example.com/sub', 'ftp://forms.example.com', 'https://user:pw@forms.example.com'] as $value) {
        [$invalid, $refused] = $proxy->set('url', $value, ['--yes' => true]);
        expect($invalid)->toBe(2)->and($refused['errors'][0]['code'])->toBe('invalid_value');
    }
});

it('follows a recreated Traefik with trusted-proxy auto, and only the app roles get the new address', function (): void {
    $config = configTraefik();
    configSharedNetwork($config->instance, ['traefik-1' => ['traefik:v3.7.15', '172.20.0.9'], 'neighbour-1' => ['nginx:1.29', '172.20.0.6']]);

    [$code, $json] = $config->set('trusted-proxy', 'auto');

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['key' => 'trusted-proxy', 'from' => '172.20.0.5', 'to' => '172.20.0.9'])
        ->and($json['data']['services']['recreated'])->toBe(['app', 'queue', 'scheduler'])
        ->and($config->state()->setting('trusted_proxies'))->toBe('172.20.0.9')
        ->and(new EnvFiles($config->instance->directory)->read('scheduler')['TRUSTED_PROXIES'])->toBe('172.20.0.9');
});

it('takes a named container or its address, and changes nothing for the trusted address without asking the engine', function (): void {
    $config = configTraefik();
    configSharedNetwork($config->instance, ['traefik-1' => ['traefik:v3.7.15', '172.20.0.5'], 'traefik-2' => ['traefik:v3.7.15', '172.20.0.7']]);
    $mark = $config->mark();

    [$same, $unchanged] = $config->set('trusted-proxy', '172.20.0.5');
    expect($same)->toBe(0)->and($unchanged['changed'])->toBeFalse()->and($config->methodsSince($mark))->toBe([]);

    [$code, $json] = $config->set('trusted-proxy', 'traefik-2');
    expect($code)->toBe(0, json_encode($json))
        ->and($config->state()->setting('proxy_container'))->toBe('traefik-2')
        ->and($config->state()->setting('trusted_proxies'))->toBe('172.20.0.7');
});

it('refuses unknown and ambiguous proxies with exit 3, invalid values with exit 2', function (): void {
    $config = configTraefik();
    configSharedNetwork($config->instance, ['traefik-a' => ['traefik:v3', '172.20.0.5'], 'traefik-b' => ['traefik:v3', '172.20.0.8']]);

    [$missing, $notFound] = $config->set('trusted-proxy', 'nobody');
    [$several, $ambiguous] = $config->set('trusted-proxy', 'auto');
    [$bad, $invalid] = $config->set('trusted-proxy', 'no spaces allowed');

    expect($missing)->toBe(3)->and($notFound['errors'][0]['code'])->toBe('trusted_proxy_not_found')
        ->and($several)->toBe(3)->and($ambiguous['errors'][0]['code'])->toBe('proxy_ambiguous')
        ->and($bad)->toBe(2)->and($invalid['errors'][0]['code'])->toBe('invalid_value');
});

it('re-reads the gateway in the port setup, where only auto applies', function (): void {
    $config = configPortProxy();
    $config->instance->docker->networkResponder = fn (string $name): ?NetworkInfo => str_ends_with($name, '_default') ? new NetworkInfo('n1', $name, false, ['172.31.0.0/16'], ['172.31.0.1']) : null;

    [$code, $json] = $config->set('trusted-proxy', 'auto');
    [$named, $refused] = $config->set('trusted-proxy', '172.31.0.1');

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['from' => '172.30.0.1', 'to' => '172.31.0.1'])
        ->and($named)->toBe(2)->and($refused['errors'][0]['hint'])->toContain('auto');
});

it('moves the loopback port of the port setup without a URL change (no --yes needed)', function (): void {
    $config = configPortProxy();

    [$code, $json] = $config->set('port', '9000');

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['url' => 'https://forms.example.com', 'to' => '9000'])
        ->and($json['data']['services']['recreated'])->toBe(['app'])
        ->and(collect($json['warnings'])->pluck('code')->all())->not->toContain('url_changed');
});

it('refuses trusted-proxy outside proxy mode', function (): void {
    [$code, $json] = new ConfigFixture()->set('trusted-proxy', 'auto');

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('not_in_this_mode');
});

it('makes a repeated install compare with the changed URL (use config set), not the installed one', function (): void {
    $config = configTraefik();
    $config->set('url', 'https://forms2.example.com', ['--yes' => true]);

    [$old, $refused] = $config->instance->run('fsa:install', InstanceCommandFixture::installFlags(['--behind-proxy' => 'traefik', '--proxy-network' => 'edge-net', '--url' => 'https://forms.example.com']));
    [$same, $unchanged] = $config->instance->run('fsa:install', InstanceCommandFixture::installFlags(['--behind-proxy' => 'traefik', '--proxy-network' => 'edge-net', '--url' => 'https://forms2.example.com']));

    expect($old)->toBe(2)->and($refused['errors'][0]['code'])->toBe('use_config_set')
        ->and($same)->toBe(0)->and($unchanged['changed'])->toBeFalse();
});

it('reverts the URL when the app does not answer for the new host', function (): void {
    $config = configTraefik();
    $config->smokeFails = fn (array $urls, array $arguments): bool => in_array('Host: forms2.example.com', $arguments, true);

    [$code, $json] = $config->set('url', 'https://forms2.example.com', ['--yes' => true]);

    expect($code)->toBe(5, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('url_check_failed')
        ->and($json['data'])->toMatchArray(['reverted' => true, 'url' => 'https://forms.example.com'])
        ->and($config->state()->url())->toBe('https://forms.example.com');
});
