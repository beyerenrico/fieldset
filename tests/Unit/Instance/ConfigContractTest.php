<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\NetworkEndpoint;
use App\Domain\Instance\Docker\NetworkInfo;
use App\Domain\Instance\Docker\ProcessOutcome;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * M10 gate (ADR 0078): what `fsa config` and `fsa install` tell an agent. `config show` says per key in which modes it
 * applies, whether it is settable now and which key to use instead; a repeated install names, per differing flag,
 * the config key that changes it (or none, with the honest way: uninstall and a fresh install); Let's Encrypt
 * staging never succeeds silently; proxy hints name the command of the context they come from, with candidates.
 */

afterEach(fn () => TemporaryInstance::cleanup());

const CONTRACT_TRAEFIK_ID = '7777777777777777777777777777777777777777777777777777777777777777';

function contractTraefik(): ConfigFixture
{
    return new ConfigFixture(['--behind-proxy' => 'traefik', '--proxy-network' => 'edge-net', '--url' => 'https://forms.example.com'], function (InstanceCommandFixture $fixture): void {
        $neighbour = str_repeat('6', 64);
        $fixture->docker->containers[CONTRACT_TRAEFIK_ID] = new ContainerInfo(CONTRACT_TRAEFIK_ID, 'traefik-1', 'running', null, null, 'traefik:v3.7.15');
        $fixture->docker->containers[$neighbour] = new ContainerInfo($neighbour, 'neighbour-1', 'running', null, null, 'nginx:1.29');
        $fixture->docker->withNetwork(new NetworkInfo('net0', 'edge-net', false, ['172.20.0.0/16'], ['172.20.0.1'], [
            new NetworkEndpoint(CONTRACT_TRAEFIK_ID, 'traefik-1', '172.20.0.5'), new NetworkEndpoint($neighbour, 'neighbour-1', '172.20.0.6'),
        ]));
    });
}

it('says per key where it applies, whether it is settable now and what to use instead (local mode)', function (): void {
    [, $json] = new ConfigFixture()->show();

    $keys = collect($json['data']['keys'])->keyBy('key');
    expect($keys['url'])->toMatchArray(['modes' => ['proxy'], 'settable_now' => false, 'use_instead' => 'port'])
        ->and($keys['port'])->toMatchArray(['modes' => ['local', 'proxy'], 'settable_now' => true, 'use_instead' => null])
        ->and($keys['domain'])->toMatchArray(['settable_now' => true])
        ->and($keys['mode'])->toMatchArray(['modes' => ['domain', 'proxy'], 'settable_now' => false, 'use_instead' => 'domain'])
        ->and($keys['acme-ca'])->toMatchArray(['modes' => ['domain'], 'settable_now' => false, 'use_instead' => 'domain'])
        ->and($keys['trusted-proxy'])->toMatchArray(['settable_now' => false, 'use_instead' => null])
        ->and($keys['mail'])->toMatchArray(['available' => true, 'settable_now' => true, 'modes' => ['local', 'proxy', 'domain'], 'value' => 'capture'])
        ->and($keys['operator-email'])->toMatchArray(['available' => true, 'settable_now' => true, 'value' => null])
        ->and($keys['backup-schedule'])->toMatchArray(['available' => false, 'settable_now' => false, 'modes' => [], 'arrives_with' => '2.0.0-rc.1']);
});

it('says the port is not settable on a shared proxy network, but url and trusted-proxy are', function (): void {
    [, $json] = contractTraefik()->show();

    $keys = collect($json['data']['keys'])->keyBy('key');
    expect($keys['port'])->toMatchArray(['settable_now' => false, 'use_instead' => null])
        ->and($keys['url'])->toMatchArray(['settable_now' => true])
        ->and($keys['trusted-proxy'])->toMatchArray(['settable_now' => true, 'value' => '172.20.0.5'])
        ->and($keys['mode'])->toMatchArray(['settable_now' => true]);
});

it('answers a deferred key without a value with setting_not_available, not a usage error', function (string $key, string $error): void {
    $fixture = new ConfigFixture;

    [$code, $json] = $fixture->instance->run('fsa:config', ['action' => 'set', 'key' => $key]);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe($error);
})->with([
    'backup schedule' => ['backup-schedule', 'setting_not_available'],
    'update check' => ['update-check', 'setting_not_available'],
    'mail without value' => ['mail', 'invalid_usage'],
    'unknown' => ['colour', 'unknown_setting'],
    'known key without value' => ['port', 'invalid_usage'],
]);

it('warns about Let\'s Encrypt staging after config set and names the switch to production first', function (): void {
    $config = new ConfigFixture;

    [$code, $json] = $config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true, '--acme-staging' => true]);
    [$production, $switched] = $config->set('acme-ca', 'production');

    $fsa = $config->instance->directory->root.'/fsa';
    expect($code)->toBe(0, json_encode($json))
        ->and(collect($json['warnings'])->firstWhere('code', 'acme_staging')['hint'])->toContain('fsa config set acme-ca production --yes')
        ->and($json['data']['next_commands'])->toBe(["{$fsa} config set acme-ca production --yes", "{$fsa} login-link <owner-email>", "{$fsa} status"])
        ->and($production)->toBe(0, json_encode($switched))
        ->and(collect($switched['warnings'])->pluck('code')->all())->not->toContain('acme_staging')
        ->and($switched['data']['next_commands'])->toBe(["{$fsa} status"]);
});

it('warns about Let\'s Encrypt staging after a domain install', function (): void {
    $fixture = new InstanceCommandFixture;
    $fixture->probes->dns[ConfigFixture::DOMAIN] = ['a' => [ConfigFixture::HOST_ADDRESS], 'aaaa' => []];
    $fixture->facts(['lanAddresses' => [ConfigFixture::HOST_ADDRESS]]);
    $fixture->docker->respondToRun(fn (ContainerSpec $spec): ?ProcessOutcome => match (true) {
        $spec->entrypoint === 'true' => new ProcessOutcome(0),
        $spec->entrypoint !== 'curl' => null,
        InstanceCommandFixture::catcherLookup($spec) instanceof ProcessOutcome => InstanceCommandFixture::catcherLookup($spec),
        str_starts_with((string) $spec->command[array_key_last($spec->command)], 'http://forms') => new ProcessOutcome(0, '308 https://forms.example.com/up'),
        default => new ProcessOutcome(0, "200 http://app:8080/up\n"),
    });

    [$code, $json] = $fixture->run('fsa:install', InstanceCommandFixture::installFlags(['--domain' => ConfigFixture::DOMAIN, '--acme-staging' => true]));

    expect($code)->toBe(0, json_encode($json))
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('acme_staging')
        ->and($json['data']['next_commands'][0])->toEndWith('/fsa config set acme-ca production --yes');
});

it('names per differing install flag the config key that changes it, and says plainly when only a reinstall does', function (): void {
    $fixture = new InstanceCommandFixture;
    $fixture->run('fsa:install', InstanceCommandFixture::installFlags());

    [$code, $json] = $fixture->run('fsa:install', InstanceCommandFixture::installFlags(['--port' => '8095', '--behind-proxy' => 'port', '--url' => 'https://forms.example.com']));

    expect($code)->toBe(2, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('use_config_set')
        ->and(collect($json['data']['flags'])->sortBy('flag')->values()->all())->toBe([
            ['flag' => '--behind-proxy', 'key' => null], ['flag' => '--port', 'key' => 'port'], ['flag' => '--url', 'key' => null],
        ], json_encode($json['data']['flags']))
        ->and($json['errors'][0]['hint'])->toContain('fsa uninstall')->toContain('2.0.0-rc.1')->toContain('--from-backup')
        ->and($json['errors'][0]['hint'])->not->toContain('back up first');
});

it('refuses mode proxy without suggesting a backup that does not exist yet', function (): void {
    [$code, $json] = new ConfigFixture()->set('mode', 'proxy', ['--yes' => true]);

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe('mode_not_settable')
        ->and($json['errors'][0]['hint'])->toContain('fsa uninstall --keep-backups')->toContain('--from-backup')->not->toContain('back up first');
});

it('accepts a repeated install whose --trusted-proxy names the trusted proxy by name, address or ID prefix', function (string $given): void {
    $config = contractTraefik();

    [$code, $json] = $config->instance->run('fsa:install', InstanceCommandFixture::installFlags(['--behind-proxy' => 'traefik', '--proxy-network' => 'edge-net', '--url' => 'https://forms.example.com', '--trusted-proxy' => $given]));

    expect($code)->toBe(0, json_encode($json))->and($json['changed'])->toBeFalse();
})->with(['name' => ['traefik-1'], 'address' => ['172.20.0.5'], 'ID prefix' => [substr(CONTRACT_TRAEFIK_ID, 0, 12)]]);

it('refuses a repeated install that names another container as trusted proxy, pointing at config set trusted-proxy', function (): void {
    $config = contractTraefik();

    [$code, $json] = $config->instance->run('fsa:install', InstanceCommandFixture::installFlags(['--behind-proxy' => 'traefik', '--proxy-network' => 'edge-net', '--url' => 'https://forms.example.com', '--trusted-proxy' => 'neighbour-1']));

    expect($code)->toBe(2)
        ->and($json['data']['flags'])->toBe([['flag' => '--trusted-proxy', 'key' => 'trusted-proxy']])
        ->and($json['errors'][0]['hint'])->toContain('fsa config set');
});

it('names config set trusted-proxy, not the install flag, with the candidates when auto finds no single proxy', function (): void {
    $config = contractTraefik();
    $second = str_repeat('8', 64);
    $config->instance->docker->containers[$second] = new ContainerInfo($second, 'traefik-2', 'running', null, null, 'traefik:v3.7.15');
    $config->instance->docker->withNetwork(new NetworkInfo('net0', 'edge-net', false, ['172.20.0.0/16'], ['172.20.0.1'], [
        new NetworkEndpoint(str_repeat('9', 64), 'traefik-a', '172.20.0.7'), new NetworkEndpoint($second, 'traefik-2', '172.20.0.8'),
    ]));
    $config->instance->docker->containers[str_repeat('9', 64)] = new ContainerInfo(str_repeat('9', 64), 'traefik-a', 'running', null, null, 'traefik:v3.7.15');

    [$code, $json] = $config->set('trusted-proxy', 'auto');

    expect($code)->toBe(3, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('proxy_ambiguous')
        ->and($json['errors'][0]['hint'])->toContain('fsa config set trusted-proxy')->not->toContain('--trusted-proxy')
        ->and($json['data']['candidates'])->toEqualCanonicalizing([['name' => 'traefik-a', 'address' => '172.20.0.7'], ['name' => 'traefik-2', 'address' => '172.20.0.8']]);
});
