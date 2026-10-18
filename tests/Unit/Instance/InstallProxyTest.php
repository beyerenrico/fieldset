<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\NetworkEndpoint;
use App\Domain\Instance\Docker\NetworkInfo;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\State\EnvFiles;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * U9 / M10-T03: `fsa install --behind-proxy traefik|caddy|port --url …` against scripted Docker (R17, R61,
 * ADR 0076): flag validation, how the single trusted proxy address is found, and resume/repeat rules. The real
 * thing runs in tests/Install/Journeys/J03ProxyModeTest.php.
 */

const PROXY_URL = 'https://forms.example.com';

const TRAEFIK_IP = '172.20.0.5';

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
});

afterEach(fn () => TemporaryInstance::cleanup());

/** Puts a shared network with a proxy and a neighbour on the scripted engine. */
function withSharedNetwork(InstanceCommandFixture $fixture, array $containers = ['traefik-1' => 'traefik:v3.7.15', 'neighbour-1' => 'nginx:1.29']): void
{
    $endpoints = [];
    $index = 5;
    foreach ($containers as $name => $image) {
        $id = str_pad((string) $index, 64, (string) $index);
        $fixture->docker->containers[$id] = new ContainerInfo($id, $name, 'running', null, null, $image);
        $endpoints[] = new NetworkEndpoint($id, $name, '172.20.0.'.$index++);
    }
    $fixture->docker->withNetwork(new NetworkInfo('net0', 'edge-net', false, ['172.20.0.0/16'], ['172.20.0.1'], $endpoints));
}

function installProxy(InstanceCommandFixture $fixture, array $flags = []): array
{
    return $fixture->run('fsa:install', InstanceCommandFixture::installFlags(['--behind-proxy' => 'traefik', '--proxy-network' => 'edge-net', '--url' => PROXY_URL, ...$flags]));
}

it('installs behind Traefik on a shared network and trusts exactly the Traefik container found by its image', function (): void {
    withSharedNetwork($this->fixture);

    [$code, $json] = installProxy($this->fixture, ['--url' => 'HTTPS://Forms.Example.com/']);
    $state = $this->fixture->state();
    $compose = ComposeFile::document($this->fixture->directory);
    $app = new EnvFiles($this->fixture->directory)->read('app');
    $phases = array_column(new OperationJournal($this->fixture->directory)->history()[0]->phases(), 'name');

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['url' => PROXY_URL, 'mode' => 'proxy', 'port' => null])
        ->and($json['data']['proxy'])->toBe(['setup' => 'traefik', 'network' => 'edge-net', 'container' => 'traefik-1', 'trusted' => [TRAEFIK_IP]])
        ->and($state->mode())->toBe('proxy')
        ->and($state->url())->toBe(PROXY_URL)
        ->and($state->setting('trusted_proxies'))->toBe(TRAEFIK_IP)
        ->and($state->setting('proxy_container'))->toBe('traefik-1')
        ->and($app['TRUSTED_PROXIES'])->toBe(TRAEFIK_IP)
        ->and($app['APP_URL'])->toBe(PROXY_URL)
        ->and($compose['networks']['proxy'])->toBe(['external' => true, 'name' => 'edge-net'])
        ->and($compose['services']['app'])->not->toHaveKey('ports')
        ->and($state->history()[0])->toMatchArray(['proxy' => 'traefik', 'url' => PROXY_URL, 'proxy_network' => 'edge-net'])
        ->and($phases)->toBe(['doctor', 'secrets', 'state', 'pull', 'up', 'migrate', 'app_install', 'start', 'smoke', 'done']);
});

it('takes the proxy named by --trusted-proxy, by container name or by its address on the network', function (string $given, string $container, string $address): void {
    withSharedNetwork($this->fixture, ['proxy-a' => 'traefik:v3', 'proxy-b' => 'docker.io/library/traefik:v3.7.15@sha256:'.str_repeat('0', 64)]);

    [$code, $json] = installProxy($this->fixture, ['--trusted-proxy' => $given]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['proxy']['container'])->toBe($container)
        ->and($json['data']['proxy']['trusted'])->toBe([$address]);
})->with([
    'name' => ['proxy-b', 'proxy-b', '172.20.0.6'],
    'address' => ['172.20.0.5', 'proxy-a', '172.20.0.5'],
    'ID prefix' => ['666666666666', 'proxy-b', '172.20.0.6'],
]);

it('recognises caddy-docker-proxy by its image', function (): void {
    withSharedNetwork($this->fixture, ['caddy' => 'lucaslorentz/caddy-docker-proxy:2.10-alpine', 'other' => 'caddy:2.11']);

    [$code, $json] = installProxy($this->fixture, ['--behind-proxy' => 'caddy']);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['proxy'])->toMatchArray(['setup' => 'caddy', 'container' => 'caddy', 'trusted' => ['172.20.0.5']])
        ->and(ComposeFile::document($this->fixture->directory)['services']['app']['labels']['caddy'])->toBe(PROXY_URL);
});

it('stops before any change when the network or the proxy cannot be found (exit 3)', function (array $containers, array $flags, string $error): void {
    if ($containers !== []) {
        withSharedNetwork($this->fixture, $containers);
    }

    [$code, $json] = installProxy($this->fixture, $flags);

    expect($code)->toBe(3, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe($error)
        ->and($json['errors'][0]['hint'])->not->toBeEmpty()
        ->and($this->fixture->directory->hasState())->toBeFalse()
        ->and($this->fixture->docker->called('composeUp'))->toBe([])
        ->and($this->fixture->directory->exists(OperationJournal::CURRENT))->toBeFalse();
})->with([
    'no such network' => [[], [], 'proxy_network_missing'],
    'no Traefik on it' => [['web' => 'nginx:1.29'], [], 'proxy_not_found'],
    'two Traefiks on it' => [['a' => 'traefik:v3', 'b' => 'traefik:v3'], [], 'proxy_ambiguous'],
    'named proxy not on it' => [['traefik-1' => 'traefik:v3'], ['--trusted-proxy' => 'elsewhere'], 'trusted_proxy_not_found'],
    'address not on it' => [['traefik-1' => 'traefik:v3'], ['--trusted-proxy' => '172.20.0.99'], 'trusted_proxy_not_found'],
]);

it('publishes a loopback port in the port setup and trusts the gateway its connections arrive from', function (): void {
    $this->fixture->docker->networkResponder = fn (string $name): ?NetworkInfo => str_ends_with($name, '_default')
        ? new NetworkInfo('net1', $name, false, ['172.31.0.0/16'], ['172.31.0.1'])
        : null;

    [$code, $json] = $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags(['--behind-proxy' => 'port', '--url' => PROXY_URL]));
    $state = $this->fixture->state();
    $journal = new OperationJournal($this->fixture->directory)->history()[0];

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['url' => PROXY_URL, 'mode' => 'proxy', 'port' => 8080])
        ->and($json['data']['proxy'])->toBe(['setup' => 'port', 'network' => null, 'container' => null, 'trusted' => ['172.31.0.1']])
        ->and($state->setting('trusted_proxies'))->toBe('172.31.0.1')
        ->and(new EnvFiles($this->fixture->directory)->read('app')['TRUSTED_PROXIES'])->toBe('172.31.0.1')
        ->and(ComposeFile::document($this->fixture->directory)['services']['app']['ports'][0]['host_ip'])->toBe('127.0.0.1')
        ->and($journal->phaseData('up'))->toMatchArray(['trusted_proxy' => '172.31.0.1'])
        ->and(array_column($this->fixture->docker->called('inspectNetwork'), 'name'))->toBe([$state->project().'_default']);
});

it('fails the port setup with exit 4 and resumes when the default network reports no gateway', function (): void {
    [$code, $json] = $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags(['--behind-proxy' => 'port', '--url' => PROXY_URL]));
    $this->fixture->docker->networkResponder = fn (string $name): NetworkInfo => new NetworkInfo('net1', $name, false, [], ['172.31.0.1']);
    [$resumed, $done] = $this->fixture->run('fsa:install');

    expect($code)->toBe(4)
        ->and($json['errors'][0]['code'])->toBe('gateway_unknown')
        ->and($resumed)->toBe(0, json_encode($done))
        ->and($done['data']['proxy']['trusted'])->toBe(['172.31.0.1']);
});

it('refuses flags that do not fit together with exit 2 before touching Docker', function (array $flags, string $error, string $needle): void {
    [$code, $json] = $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags($flags));

    expect($code)->toBe(2, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe($error)
        ->and($json['errors'][0]['message'].' '.json_encode($json['data']))->toContain($needle)
        ->and($this->fixture->docker->calls)->toBe([]);
})->with([
    'proxy without --url' => [['--behind-proxy' => 'port'], 'input_required', '--url'],
    'traefik without a network' => [['--behind-proxy' => 'traefik', '--url' => PROXY_URL], 'input_required', '--proxy-network'],
    '--url without proxy mode' => [['--url' => PROXY_URL], 'invalid_combination', '--url'],
    'proxy and domain (R61)' => [['--behind-proxy' => 'port', '--url' => PROXY_URL, '--domain' => 'forms.example.com'], 'invalid_combination', '--domain'],
    'network in the port setup' => [['--behind-proxy' => 'port', '--url' => PROXY_URL, '--proxy-network' => 'edge-net'], 'invalid_combination', '--proxy-network'],
    'trusted proxy without a network setup' => [['--trusted-proxy' => 'traefik'], 'invalid_combination', '--trusted-proxy'],
    '--port on a shared network' => [['--behind-proxy' => 'caddy', '--url' => PROXY_URL, '--proxy-network' => 'edge-net', '--port' => '8081'], 'invalid_combination', '--port'],
    'URL with a path' => [['--behind-proxy' => 'port', '--url' => 'https://example.com/forms'], 'invalid_flag', '--url'],
    'URL with a query' => [['--behind-proxy' => 'port', '--url' => 'https://example.com/?a=1'], 'invalid_flag', '--url'],
    'URL with credentials' => [['--behind-proxy' => 'port', '--url' => 'https://u:p@example.com'], 'invalid_flag', '--url'],
    'not http' => [['--behind-proxy' => 'port', '--url' => 'ftp://example.com'], 'invalid_flag', '--url'],
    'network name' => [['--behind-proxy' => 'traefik', '--url' => PROXY_URL, '--proxy-network' => 'bad name'], 'invalid_flag', '--proxy-network'],
    'trusted proxy' => [['--behind-proxy' => 'traefik', '--url' => PROXY_URL, '--proxy-network' => 'n', '--trusted-proxy' => '-x'], 'invalid_flag', '--trusted-proxy'],
]);

it('changes nothing on a repeat with the same flags and refuses a different URL (use config set)', function (): void {
    withSharedNetwork($this->fixture);
    installProxy($this->fixture);
    $calls = count($this->fixture->docker->calls);

    [$sameCode, $same] = installProxy($this->fixture);
    [$otherCode, $other] = installProxy($this->fixture, ['--url' => 'https://other.example.com']);

    expect($sameCode)->toBe(0, json_encode($same))
        ->and($same['changed'])->toBeFalse()
        ->and($same['data']['url'])->toBe(PROXY_URL)
        ->and($this->fixture->docker->methodsSince($calls))->toBe([])
        ->and($otherCode)->toBe(2)
        ->and($other['errors'][0]['code'])->toBe('use_config_set')
        ->and($other['errors'][0]['message'])->toContain('--url');
});

it('resumes an interrupted proxy install with the recorded flags and refuses changing them after the preflight', function (): void {
    withSharedNetwork($this->fixture);
    $this->fixture->oneOffs['migrate'] = new ProcessOutcome(1, '', 'failed');
    [$failed] = installProxy($this->fixture);
    unset($this->fixture->oneOffs['migrate']);

    [$conflictCode, $conflict] = installProxy($this->fixture, ['--url' => 'https://other.example.com']);
    [$resumedCode, $resumed] = $this->fixture->run('fsa:install');

    expect($failed)->toBe(4)
        ->and($conflictCode)->toBe(2)
        ->and($conflict['errors'][0]['code'])->toBe('flag_already_applied')
        ->and($conflict['errors'][0]['message'])->toContain('--url')
        ->and($resumedCode)->toBe(0, json_encode($resumed))
        ->and($resumed['data']['url'])->toBe(PROXY_URL)
        ->and($this->fixture->state()->history()[0]['proxy'])->toBe('traefik');
});

it('reports no app port in status and probes none in doctor on a shared proxy network', function (): void {
    withSharedNetwork($this->fixture);
    installProxy($this->fixture);
    $this->fixture->withRunningStack();
    $probesBefore = count($this->fixture->runSpecs('true'));

    [$statusCode, $status] = $this->fixture->run('fsa:status');
    [$doctorCode, $doctor] = $this->fixture->run('fsa:doctor');
    $probed = array_map(fn ($spec): string => $spec->ports[0] ?? '', array_slice($this->fixture->runSpecs('true'), $probesBefore));
    $ports = array_values(array_filter($doctor['data']['checks'], fn (array $check): bool => $check['key'] === 'ports'))[0];

    expect($statusCode)->toBe(0, json_encode($status))
        ->and($status['data'])->toMatchArray(['mode' => 'proxy', 'url' => PROXY_URL, 'port' => null])
        ->and($doctorCode)->toBe(0, json_encode($doctor))
        ->and($ports['details']['ports'])->toBe(['mail_ui' => 8025])
        ->and(implode(',', $probed))->not->toContain(':8080');
});
