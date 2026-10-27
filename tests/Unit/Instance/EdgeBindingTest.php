<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\CaddyfileRenderer;
use App\Domain\Instance\Compose\LocalCaSite;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerRuntime;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Docker\WslBackend;
use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\Checks\LocalCaPorts;
use App\Domain\Instance\Doctor\CheckStatus;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\Doctor\PortProbe;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Install\InstallMode;
use App\Domain\Instance\Install\InstallRequest;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\LocalCa\EdgeBinding;
use App\Domain\Instance\LocalCa\LocalCaStore;
use App\Domain\Instance\Windows\FactsScope;
use App\Domain\Instance\Windows\WindowsFacts;
use App\Domain\Instance\Windows\WindowsFactsParser;
use App\Domain\Instance\Windows\WslNetworking;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\LocalCaFixture;
use Tests\Support\Instance\PhoneFixtures;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\TunnelFixture;

/*
 * 2.1.1 U6 (R11, KTD13): the advertised address (SAN, phone URL, `lan-address`) is the Windows LAN address; the edge
 * binds it on Docker Desktop and on Engine with mirrored networking, and every address of the distro on Engine with NAT
 * (or an unknown mode). The decision is recorded as `lan_bind: all`, and the port probe asks where the edge will bind.
 * Hosts outside WSL bind and probe the LAN address exactly as before.
 */

const BINDING_UUID = '3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192';

/** Host facts of a setup: outside WSL (null backend) or WSL 2 with a backend and the networking mode Windows reported. */
function bindingFacts(?WslBackend $backend, WslNetworking $networking = WslNetworking::Nat, array $lanAddresses = [PhoneFixtures::LAN]): HostFacts
{
    $windows = new WindowsFacts(FactsScope::Full, null, $networking);

    return $backend instanceof WslBackend
        ? new HostFacts(os: 'linux', lanAddresses: $lanAddresses, wslVersion: 2, wslBackend: $backend, windows: $windows)
        : new HostFacts(os: 'linux', lanAddresses: $lanAddresses);
}

/** Scripted local-CA edge for config set: the root on the volume, HTTPS without SNI, port 80 (308 / trust page). */
function bindingEdge(TunnelFixture $fixture): void
{
    $instance = $fixture->config->instance;
    $previous = $instance->docker->runResponder;
    $root = LocalCaFixture::root();
    $instance->docker->respondToRun(function (ContainerSpec $spec) use ($previous, $root): ?ProcessOutcome {
        $last = $spec->command[array_key_last($spec->command)] ?? '';
        if ($spec->entrypoint === 'cat' && $last === LocalCaStore::ROOT_FILE) {
            return new ProcessOutcome(0, $root);
        }
        if ($spec->entrypoint === 'curl' && in_array('--cacert', $spec->command, true)) {
            return new ProcessOutcome(0, '200');
        }
        if ($spec->entrypoint === 'curl' && str_contains(implode(' ', $spec->command), ':80:')) {
            preg_match('#^http://([0-9.]+)/up$#', $spec->command[array_search('--write-out', $spec->command, true) + 2] ?? '', $address);

            return new ProcessOutcome(0, '308 https://'.($address[1] ?? '').'/up'."\n200 \n");
        }

        return $previous instanceof Closure ? $previous($spec) : null;
    });
}

/** @return list<list<string>> the port bindings of the port probes since $from */
function bindingProbes(InstanceCommandFixture $fixture, int $from = 0): array
{
    return array_map(static fn (ContainerSpec $spec): array => $spec->ports, array_slice($fixture->runSpecs('true'), $from));
}

afterEach(fn () => TemporaryInstance::cleanup());

it('decides the bind address per setup (KTD13)', function (?WslBackend $backend, WslNetworking $networking, bool $all): void {
    $binding = EdgeBinding::decide(PhoneFixtures::LAN, bindingFacts($backend, $networking));

    expect($binding->advertised)->toBe(PhoneFixtures::LAN)
        ->and($binding->bindsAll())->toBe($all)
        ->and($binding->bindAddress)->toBe($all ? null : PhoneFixtures::LAN);
})->with([
    'not WSL' => [null, WslNetworking::Unknown, false],
    'Docker Desktop' => [WslBackend::DockerDesktop, WslNetworking::Nat, false],
    'Docker Desktop, mode unknown' => [WslBackend::DockerDesktop, WslNetworking::Unknown, false],
    'Engine with NAT' => [WslBackend::Engine, WslNetworking::Nat, true],
    'Engine with mirrored networking' => [WslBackend::Engine, WslNetworking::Mirrored, false],
    'Engine, mode unknown (interop off)' => [WslBackend::Engine, WslNetworking::Unknown, true],
]);

it('binds the advertised address for a WSL 2 launcher that reported no backend, and reads the mode from the shim\'s facts', function (): void {
    $older = HostFacts::fromEnvironment(['FIELDSET_WSL' => '2', 'FIELDSET_LAN_ADDRESSES' => PhoneFixtures::LAN]);
    $mirrored = HostFacts::fromEnvironment(['FIELDSET_WSL' => '2', 'FIELDSET_WSL_BACKEND' => 'engine', 'FIELDSET_WINDOWS_FACTS' => "scope=full\nnetworking=mirrored\nend=1\n"]);

    expect(EdgeBinding::decide(PhoneFixtures::LAN, $older)->bindsAll())->toBeFalse()
        ->and(EdgeBinding::decide(PhoneFixtures::LAN, $mirrored)->bindsAll())->toBeFalse()
        ->and(WindowsFactsParser::parse("scope=full\nnetworking=mirrored\nend=1\n")->networking)->toBe(WslNetworking::Mirrored);
});

it('renders the edge without a host address for lan_bind all, while the certificate and URL keep the LAN address', function (): void {
    $all = PhoneFixtures::render(PhoneFixtures::localCa([EdgeBinding::SETTING => EdgeBinding::ALL]));
    $address = PhoneFixtures::render(PhoneFixtures::localCa());

    expect($all->service('edge')['ports'])->toBe([
        ['target' => 80, 'published' => '80', 'protocol' => 'tcp'],
        ['target' => 443, 'published' => '443', 'protocol' => 'tcp'],
    ])
        ->and($all->files['edge/Caddyfile'])->toBe(new CaddyfileRenderer()->render(new LocalCaSite(PhoneFixtures::LAN)))
        ->and($all->environments['app']['APP_URL'])->toBe('https://'.PhoneFixtures::LAN)
        ->and($address->service('edge')['ports'][0])->toBe(['target' => 80, 'published' => '80', 'host_ip' => PhoneFixtures::LAN, 'protocol' => 'tcp']);
});

it('records lan_bind at install only for Engine with NAT; other hosts keep the three settings', function (): void {
    $request = InstallRequest::fromOptions(['phone-https' => 'local-ca']);
    $localCa = fn (HostFacts $facts): ?array => InstallMode::localCa($request, null, BINDING_UUID, $facts, static fn (): array => []);

    expect($localCa(bindingFacts(WslBackend::Engine)))->toMatchArray(['lan_address' => PhoneFixtures::LAN, EdgeBinding::SETTING => EdgeBinding::ALL])
        ->and(array_keys($localCa(bindingFacts(WslBackend::DockerDesktop)) ?? []))->toBe(['phone_https', 'lan_address', 'edge_subnet'])
        ->and(array_keys($localCa(bindingFacts(null)) ?? []))->toBe(['phone_https', 'lan_address', 'edge_subnet']);
});

it('probes 80 and 443 where the edge binds: every address for lan_bind all, the LAN address otherwise', function (bool $all, string $host): void {
    $fixture = new InstanceCommandFixture;
    $fixture->run('fieldset:install', InstanceCommandFixture::installFlags());
    $state = $fixture->state();
    $binding = EdgeBinding::recorded(PhoneFixtures::LAN, $all ? EdgeBinding::ALL : null);
    $from = count($fixture->runSpecs('true'));
    $context = new DoctorContext(new HostFacts(lanAddresses: [PhoneFixtures::LAN]), $fixture->directory, $fixture->docker, $fixture->probes, $state, null, InstanceCommandFixture::appImage(), uuid: $state->uuid(), lanAddress: PhoneFixtures::LAN, edgeBinding: $binding);

    $result = new LocalCaPorts(app(PortProbe::class))->check($context, InstanceCommandFixture::appImage(), CheckResult::ok('ports', 'Loopback fine.'));

    expect($result->status)->toBe(CheckStatus::Ok)
        ->and($result->summary)->toContain("Ports 80 and 443 on {$binding->where()} are free for the edge.")
        ->and($result->details['lan']['address'])->toBe(PhoneFixtures::LAN)
        ->and(bindingProbes($fixture, $from))->toBe([["{$host}:80:8080"], ["{$host}:443:8080"]]);
})->with([
    'every address' => [true, '0.0.0.0'],
    'the LAN address' => [false, PhoneFixtures::LAN],
]);

it('turns the local CA on for Engine with NAT: edge on every address, certificate and URL on the Windows address', function (): void {
    $fixture = new TunnelFixture;
    $instance = $fixture->config->instance;
    $instance->facts(['lanAddresses' => [PhoneFixtures::LAN], 'wslVersion' => 2, 'wslBackend' => WslBackend::Engine, 'runtime' => DockerRuntime::Rootful,
        'windows' => new WindowsFacts(FactsScope::Full, null, WslNetworking::Nat), 'wslAddresses' => ['172.29.20.5']]);
    bindingEdge($fixture);
    $from = count($instance->runSpecs('true'));

    [$code, $json] = $fixture->config->set('phone-https', 'local-ca', ['--yes' => true]);
    $edge = ComposeFile::document($instance->directory)['services']['edge'] ?? [];

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($fixture->config->state()->setting(EdgeBinding::SETTING))->toBe(EdgeBinding::ALL)
        ->and($json['data']['url'])->toBe('https://'.PhoneFixtures::LAN)
        ->and($edge['ports'] ?? null)->toBe([['target' => 80, 'published' => '80', 'protocol' => 'tcp'], ['target' => 443, 'published' => '443', 'protocol' => 'tcp']])
        ->and($instance->directory->read('edge/Caddyfile'))->toContain('default_sni "'.PhoneFixtures::LAN.'"')
        ->and(bindingProbes($instance, $from))->toBe([['0.0.0.0:80:8080'], ['0.0.0.0:443:8080']]);
});

it('binds the address again after config set lan-address on mirrored networking, and leaves no lan_bind on Docker Desktop', function (): void {
    $engine = new TunnelFixture;
    $wsl = ['lanAddresses' => [PhoneFixtures::LAN, '192.168.1.30'], 'wslVersion' => 2, 'wslBackend' => WslBackend::Engine];
    $engine->config->instance->facts([...$wsl, 'windows' => new WindowsFacts(FactsScope::Full, null, WslNetworking::Nat)]);
    bindingEdge($engine);
    $engine->config->set('phone-https', 'local-ca', ['--yes' => true]);
    $engine->config->instance->facts([...$wsl, 'windows' => new WindowsFacts(FactsScope::Full, null, WslNetworking::Mirrored)]);
    [$moved, $movedJson] = $engine->config->set('lan-address', '192.168.1.30', ['--yes' => true]);
    $desktop = new TunnelFixture;
    $desktop->config->instance->facts([...$wsl, 'wslBackend' => WslBackend::DockerDesktop]);
    bindingEdge($desktop);
    [$code, $json] = $desktop->config->set('phone-https', 'local-ca', ['--yes' => true]);

    expect($moved)->toBe(0, (string) json_encode($movedJson))
        ->and($engine->config->state()->setting(EdgeBinding::SETTING))->toBeNull()
        ->and(ComposeFile::document($engine->config->instance->directory)['services']['edge']['ports'][0] ?? null)
        ->toBe(['target' => 80, 'published' => '80', 'host_ip' => '192.168.1.30', 'protocol' => 'tcp'])
        ->and($code)->toBe(0, (string) json_encode($json))
        ->and(array_key_exists(EdgeBinding::SETTING, $desktop->config->state()->toArray()['settings'] ?? []))->toBeFalse()
        ->and(ComposeFile::document($desktop->config->instance->directory)['services']['edge']['ports'][0]['host_ip'] ?? null)->toBe(PhoneFixtures::LAN);
});
