<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\CaddyfileRenderer;
use App\Domain\Instance\Compose\LocalCaSite;
use App\Domain\Instance\Compose\Services\EdgeService;
use App\Domain\Instance\Compose\Services\TunnelService;
use App\Domain\Instance\Compose\TunnelNetwork;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Release\BuildFlavor;
use App\Domain\Instance\State\InputGrammar;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InvalidState;
use App\Domain\Instance\Tunnel\HarnessTunnel;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\PhoneFixtures;

/*
 * Plan U14 (R19, R20, R61, KTD10, KTD12; ADR 0082): the local-CA edge on the LAN address, and the quick tunnel on its
 * own network with a fixed address, restart "no" and no secret.
 */

afterEach(fn () => putenv(HarnessTunnel::ENV));

it('renders the local-CA edge in local mode with 80 and 443 on the LAN address only', function (): void {
    $rendered = PhoneFixtures::render(PhoneFixtures::localCa());
    $edge = $rendered->service('edge');
    $app = $rendered->service('app');

    expect($edge['image'])->toBe(ComposeFixtures::digests()['caddy'])
        ->and($edge['ports'])->toBe([
            ['target' => 80, 'published' => '80', 'host_ip' => PhoneFixtures::LAN, 'protocol' => 'tcp'],
            ['target' => 443, 'published' => '443', 'host_ip' => PhoneFixtures::LAN, 'protocol' => 'tcp'],
        ])
        ->and($edge['networks'])->toBe(['edge' => ['ipv4_address' => PhoneFixtures::EDGE_ADDRESS]])
        ->and($edge['cap_add'])->toBe(['NET_BIND_SERVICE'])
        ->and($rendered->environments['edge'])->toBe([])
        ->and($app['networks'])->toContain('edge')
        ->and($app['ports'][0])->toMatchArray(['host_ip' => '127.0.0.1', 'target' => 8080])
        ->and($rendered->environments['app']['TRUSTED_PROXIES'])->toBe(PhoneFixtures::EDGE_ADDRESS)
        ->and($rendered->environments['app']['APP_URL'])->toBe('https://'.PhoneFixtures::LAN)
        ->and($rendered->files['edge/Caddyfile'])->toBe(new CaddyfileRenderer()->render(new LocalCaSite(PhoneFixtures::LAN)))
        ->and($rendered->service('mailpit')['networks'])->toBe(['default']);
});

it('renders the local-CA Caddyfile: internal CA, default_sni, port 80 only for the trust page', function (): void {
    $caddyfile = new CaddyfileRenderer()->render(new LocalCaSite(PhoneFixtures::LAN));

    expect($caddyfile)->toContain("\tskip_install_trust\n")
        ->toContain("\tauto_https disable_redirects\n")
        ->toContain("\tdefault_sni \"192.168.1.20\"\n")
        ->toContain("\t\tca local {\n\t\t\tname \"form-schema-app local CA\"\n")
        ->toContain("\"https://192.168.1.20\" {\n\ttls internal\n\thandle /_fsa/* {\n\t\trespond 404\n\t}\n\thandle {\n\t\treverse_proxy app:8080 {\n\t\t\theader_up -X-Forwarded-Port\n")
        ->toContain("\"http://\" {\n\t@phone_trust path /phone-trust /phone-trust/*\n\thandle @phone_trust {\n\t\treverse_proxy app:8080 {")
        ->toContain("\thandle {\n\t\tredir \"https://192.168.1.20{uri}\" 308\n\t}")
        ->toContain('http://:8081 {')
        ->not->toContain('acme_ca')
        ->not->toContain('FIELDSET_CATCHER_AUTHORIZATION');
});

it('refuses a local-CA address that is not IPv4 as a renderer bug (exit 1)', function (string $address): void {
    expect(fn () => new CaddyfileRenderer()->render(new LocalCaSite($address)))->toThrow(InstanceException::class);
})->with(['fe80::1', 'forms.example.com', '192.168.1.20"}', '']);

it('needs the LAN address and the edge subnet for the local-CA edge', function (array $settings): void {
    expect(fn () => PhoneFixtures::render(PhoneFixtures::localCa($settings)))->toThrow(InvalidState::class);
})->with([[['lan_address' => null]], [['edge_subnet' => null]]]);

it('renders the quick tunnel on its own network with a fixed address, restart no and no secret', function (): void {
    $rendered = PhoneFixtures::render(PhoneFixtures::tunnel());
    $tunnel = $rendered->service('tunnel');

    expect($tunnel['image'])->toBe(ComposeFixtures::digests()['cloudflared'])
        ->and($tunnel['command'])->toBe(['tunnel', '--metrics', '0.0.0.0:'.TunnelService::METRICS_PORT, '--url', 'http://app:8080'])
        ->and($tunnel['restart'])->toBe('no')
        ->and($tunnel['user'])->toBe(TunnelService::USER)
        ->and($tunnel['read_only'])->toBeTrue()
        ->and($tunnel['cap_drop'])->toBe(['ALL'])
        ->and($tunnel['security_opt'])->toBe(['no-new-privileges:true'])
        ->and($tunnel)->not->toHaveKey('ports')
        ->and($tunnel['networks'])->toBe(['tunnel' => ['ipv4_address' => PhoneFixtures::PEER]])
        ->and($tunnel['depends_on'])->toBe(['app' => ['condition' => 'service_healthy']])
        ->and($tunnel['mem_limit'])->toBe($rendered->memoryLimits()['tunnel'].'m')
        ->and($rendered->environments['tunnel'])->toBe([])
        ->and($rendered->document['networks']['tunnel']['ipam']['config'][0])->toBe(TunnelNetwork::ipam(PhoneFixtures::TUNNEL_SUBNET))
        ->and($rendered->service('app')['networks'])->toContain('tunnel')
        ->and($rendered->service('queue')['networks'])->not->toContain('tunnel')
        ->and($rendered->environments['app'])->not->toHaveKey('TRUSTED_PROXIES')
        ->and($rendered->services())->not->toContain('edge');
});

it('keeps the gateway and cloudflared out of the dynamic range and picks the tunnel subnet from its own pool', function (): void {
    $ipam = TunnelNetwork::ipam(PhoneFixtures::TUNNEL_SUBNET);
    $subnet = TunnelNetwork::choose(ComposeFixtures::UUID, []);

    expect($ipam)->toBe(['subnet' => PhoneFixtures::TUNNEL_SUBNET, 'ip_range' => '10.215.7.24/29', 'gateway' => '10.215.7.17'])
        ->and($subnet)->toStartWith('10.215.')
        ->and(InputGrammar::isPrivateSubnet($subnet))->toBeTrue()
        ->and(TunnelNetwork::choose(ComposeFixtures::UUID, [$subnet]))->not->toBe($subnet);
});

it('renders nothing for phone HTTPS outside local mode, without it, or with a value of a newer version', function (): void {
    $plain = ComposeFixtures::render();
    $unknown = PhoneFixtures::render(ComposeFixtures::state(['phone_https' => 'carrier-pigeon', 'tunnel_subnet' => PhoneFixtures::TUNNEL_SUBNET]));
    $domainSettings = InstanceSettings::of(PhoneFixtures::localCa()->withMode('domain'));

    expect($plain->services())->not->toContain('tunnel')->not->toContain('edge')
        ->and($unknown->services())->not->toContain('tunnel')->not->toContain('edge')
        ->and($domainSettings->phoneHttps())->toBeNull()
        ->and($domainSettings->localCa())->toBeFalse();
});

it('needs the tunnel subnet for the quick tunnel', function (): void {
    expect(fn () => PhoneFixtures::render(PhoneFixtures::tunnel(['tunnel_subnet' => null])))->toThrow(InvalidState::class);
});

it('swaps in the fake tunnel image only in harness builds and only for an fsa-test image', function (): void {
    putenv(HarnessTunnel::ENV.'=image=fsa-test-fake-tunnel:abc123;ttl=30');

    expect(HarnessTunnel::image(null, BuildFlavor::Harness))->toBe('fsa-test-fake-tunnel:abc123')
        ->and(HarnessTunnel::ttl(null, BuildFlavor::Harness))->toBe(30)
        ->and(HarnessTunnel::image(null, BuildFlavor::Production))->toBeNull()
        ->and(HarnessTunnel::ttl(null, BuildFlavor::Production))->toBeNull()
        ->and(PhoneFixtures::render(PhoneFixtures::tunnel())->service('tunnel')['image'])->toBe(ComposeFixtures::digests()['cloudflared'])
        ->and(HarnessTunnel::image('image=ghcr.io/evil/cloudflared:latest', BuildFlavor::Harness))->toBeNull()
        ->and(HarnessTunnel::image('image=fsa-test-x:1;ttl=5', BuildFlavor::Harness))->toBeNull()
        ->and(HarnessTunnel::image('image=fsa-test-x:1;colour=red', BuildFlavor::Harness))->toBeNull()
        ->and(HarnessTunnel::ttl('ttl=99999', BuildFlavor::Harness))->toBeNull()
        ->and(HarnessTunnel::image('', BuildFlavor::Harness))->toBeNull();
});

it('names the edge certificate volume once for both edges', function (): void {
    expect(PhoneFixtures::render(PhoneFixtures::localCa())->document['volumes'])->toHaveKey(EdgeService::CERTIFICATE_VOLUME);
});
