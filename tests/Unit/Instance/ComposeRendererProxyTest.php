<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\ProxyLabels;
use App\Domain\Instance\Compose\RenderedProject;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\RuntimeWriter;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U9 (R17, ADR 0076): proxy mode in the compose renderer. Only the app joins the proxy's external network,
 * with Traefik or caddy-docker-proxy labels, or the app publishes a loopback port; the app trusts exactly the
 * proxy's address; the catcher UI stays on loopback; there is no edge. The real thing runs in
 * tests/Install/Journeys/J03ProxyModeTest.php.
 */

afterEach(fn () => TemporaryInstance::cleanup());

function renderProxy(string $setup = 'traefik', string $url = 'https://forms.example.com', array $settings = []): RenderedProject
{
    return new ComposeRenderer()->render(ComposeFixtures::proxyState($setup, $url, $settings), ComposeFixtures::secrets());
}

it('lets only the app join the shared network, declared external and never labelled or created by Compose', function (string $setup): void {
    $rendered = renderProxy($setup);
    $services = $rendered->document['services'];

    expect($rendered->document['networks'][ProxyLabels::NETWORK])->toBe(['external' => true, 'name' => 'edge-net'])
        ->and($services['app']['networks'])->toBe(['default', 'renderer', ProxyLabels::NETWORK])
        ->and($services['app'])->not->toHaveKey('ports');
    foreach (['queue', 'scheduler', 'postgres', 'redis', 'gotenberg', 'mailpit', 'backup'] as $service) {
        expect($services[$service]['networks'])->not->toContain(ProxyLabels::NETWORK, $service);
    }
    expect($rendered->document['networks']['default']['labels'][Labels::INSTANCE])->toBe(ComposeFixtures::UUID)
        ->and($rendered->services())->toBe(['app', 'queue', 'scheduler', 'postgres', 'redis', 'gotenberg', 'mailpit', 'backup']);
})->with(['traefik', 'caddy']);

it('routes Traefik to the app over the shared network by the --url host, with TLS for an https URL', function (): void {
    $rendered = renderProxy('traefik', 'https://forms.example.com:8443');
    $router = $rendered->project;

    expect($rendered->service('app')['labels'])->toBe([
        'traefik.enable' => 'true',
        'traefik.docker.network' => 'edge-net',
        "traefik.http.routers.{$router}.rule" => 'Host(`forms.example.com`)',
        "traefik.http.routers.{$router}.service" => $router,
        "traefik.http.routers.{$router}.tls" => 'true',
        "traefik.http.services.{$router}.loadbalancer.server.port" => '8080',
        Labels::INSTANCE => ComposeFixtures::UUID,
        Labels::ROLE => 'app',
    ])->and($rendered->service('queue')['labels'])->toBe(Labels::forInstance(ComposeFixtures::UUID, 'queue'));
});

it('leaves TLS to the proxy entry point for a plain http URL', function (): void {
    $labels = renderProxy('traefik', 'http://forms.internal.example')->service('app')['labels'];

    expect($labels)->not->toHaveKey('traefik.http.routers.'.ComposeFixtures::state()->project().'.tls')
        ->and(array_filter(array_keys($labels), fn (string $key): bool => str_ends_with($key, '.tls')))->toBe([]);
});

it('gives caddy-docker-proxy the URL origin as the site and the app port as upstream', function (string $url, string $site): void {
    $labels = renderProxy('caddy', $url)->service('app')['labels'];

    expect($labels)->toMatchArray(['caddy' => $site, 'caddy.reverse_proxy' => '{{upstreams 8080}}', 'caddy_ingress_network' => 'edge-net'])
        ->and(array_filter(array_keys($labels), fn (string $key): bool => str_starts_with($key, 'traefik')))->toBe([]);
})->with([
    'https' => ['https://forms.example.com', 'https://forms.example.com'],
    'http with a port' => ['http://forms.example.com:8081', 'http://forms.example.com:8081'],
]);

it('publishes only the loopback port in the port setup, without a shared network or labels', function (): void {
    $rendered = renderProxy('port', 'https://forms.example.com', ['port' => 8090]);
    $app = $rendered->service('app');

    expect($app['ports'])->toBe([['target' => 8080, 'published' => '8090', 'host_ip' => '127.0.0.1', 'protocol' => 'tcp']])
        ->and($app['networks'])->toBe(['default', 'renderer'])
        ->and($app['labels'])->toBe(Labels::forInstance(ComposeFixtures::UUID, 'app'))
        ->and($rendered->document['networks'])->not->toHaveKey(ProxyLabels::NETWORK);
});

it('keeps the catcher UI on a loopback port and its SMTP port unpublished in every setup', function (string $setup): void {
    $mailpit = renderProxy($setup, 'https://forms.example.com', ['mail_ui_port' => 8026])->service('mailpit');

    expect($mailpit['ports'])->toBe([['target' => 8025, 'published' => '8026', 'host_ip' => '127.0.0.1', 'protocol' => 'tcp']])
        ->and(json_encode($mailpit))->not->toContain('1025');
})->with(['traefik', 'caddy', 'port']);

it('trusts exactly the proxy address in the app roles, nothing in other services, and nothing in local mode', function (string $setup): void {
    $rendered = renderProxy($setup);

    foreach (['app', 'queue', 'scheduler'] as $role) {
        expect($rendered->environments[$role]['TRUSTED_PROXIES'])->toBe(ComposeFixtures::PROXY_ADDRESS, $role);
    }
    foreach (['postgres', 'redis', 'gotenberg', 'mailpit'] as $service) {
        expect($rendered->environments[$service])->not->toHaveKey('TRUSTED_PROXIES');
    }
    expect(ComposeFixtures::render()->environments['app'])->not->toHaveKey('TRUSTED_PROXIES');
})->with(['traefik', 'caddy', 'port']);

it('takes the instance URL and the HTTPS expectation from --url and renders no local URL', function (): void {
    $directory = TemporaryInstance::directory();
    $state = ComposeFixtures::proxyState();
    new ComposeRenderer()->write($directory, $state, ComposeFixtures::secrets());
    $runtime = json_decode((string) $directory->read(RuntimeWriter::FILE), true);
    $env = renderProxy()->environments['app'];

    expect($runtime['instance_url'])->toBe('https://forms.example.com')
        ->and($runtime['local_url'])->toBeNull()
        ->and($env['APP_URL'])->toBe('https://forms.example.com')
        ->and($env['SESSION_SECURE_COOKIE'])->toBe('true')
        ->and((string) $directory->read('compose.yaml'))->toContain('"external": true');
});

it('accepts single addresses only as trusted proxies, never a range', function (string $value): void {
    expect(fn () => ComposeFixtures::proxyState(settings: ['trusted_proxies' => $value]))->toThrow(InvalidInput::class);
})->with(['172.20.0.0/16', '172.20.0.*', '10.0.0.1, ', 'traefik']);

it('reads the proxy settings only in proxy mode', function (): void {
    $proxy = InstanceSettings::of(ComposeFixtures::proxyState('caddy'));
    $local = InstanceSettings::of(ComposeFixtures::state(['proxy' => 'traefik', 'proxy_network' => 'edge-net']));

    expect($proxy->proxy())->toBe('caddy')
        ->and($proxy->proxyNetwork())->toBe('edge-net')
        ->and($proxy->proxyContainer())->toBe('edge-proxy-1')
        ->and($proxy->trustedProxies())->toBe([ComposeFixtures::PROXY_ADDRESS])
        ->and($proxy->joinsProxyNetwork())->toBeTrue()
        ->and($proxy->publishesAppPort())->toBeFalse()
        ->and($local->proxy())->toBeNull()
        ->and($local->joinsProxyNetwork())->toBeFalse()
        ->and($local->publishesAppPort())->toBeTrue();
});
