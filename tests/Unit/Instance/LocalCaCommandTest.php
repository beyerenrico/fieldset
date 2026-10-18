<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\LocalCa\LocalCaStore;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\LocalCaFixture;
use Tests\Support\Instance\PhoneFixtures;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\TunnelFixture;

/*
 * Plan U14 (R19, R21, R29, R61; KTD10; ADR 0082): the local CA through `fsa install --phone-https local-ca` and
 * `fsa config set phone-https|lan-address`, against scripted Docker. The edge's answers come from localCaEdge().
 */

/** Scripted local-CA edge: the root on the volume, HTTPS without SNI, port 80 (308 / trust page), the key deletion. */
function localCaEdge(TunnelFixture|InstanceCommandFixture $fixture, ?string $root = null, bool $edgeFails = false): object
{
    $instance = $fixture instanceof TunnelFixture ? $fixture->config->instance : $fixture;
    $log = new class
    {
        /** @var list<string> */
        public array $calls = [];
    };
    $root ??= LocalCaFixture::root();
    $previous = $instance->docker->runResponder;
    $instance->docker->respondToRun(function (ContainerSpec $spec) use ($previous, $root, $edgeFails, $log): ?ProcessOutcome {
        $last = $spec->command[array_key_last($spec->command)] ?? '';
        if ($spec->entrypoint === 'cat' && $last === LocalCaStore::ROOT_FILE) {
            $log->calls[] = 'cat root';

            return new ProcessOutcome(0, $root);
        }
        if ($spec->entrypoint === 'rm') {
            $log->calls[] = 'rm '.implode(' ', $spec->command);

            return new ProcessOutcome(0);
        }
        if ($spec->entrypoint === 'curl' && in_array('--cacert', $spec->command, true)) {
            $log->calls[] = 'https '.$last;

            return $edgeFails ? new ProcessOutcome(60, '000', 'curl: (60) SSL certificate problem') : new ProcessOutcome(0, '200');
        }
        if ($spec->entrypoint === 'curl' && str_contains(implode(' ', $spec->command), ':80:')) {
            preg_match('#^http://([0-9.]+)/up$#', $spec->command[array_search('--write-out', $spec->command, true) + 2] ?? '', $address);
            $log->calls[] = 'http '.($address[1] ?? '?');

            return new ProcessOutcome(0, '308 https://'.($address[1] ?? '').'/up'."\n200 \n");
        }

        return $previous instanceof Closure ? $previous($spec) : null;
    });

    return $log;
}

afterEach(fn () => TemporaryInstance::cleanup());

it('installs with --phone-https local-ca on the best LAN address: edge, https URL, root fingerprint', function (): void {
    $fixture = new InstanceCommandFixture;
    $fixture->facts(['lanAddresses' => ['172.17.0.1', '10.0.0.5', PhoneFixtures::LAN]]);
    $root = LocalCaFixture::root();
    $log = localCaEdge($fixture, $root);

    [$code, $json] = $fixture->run('fsa:install', InstanceCommandFixture::installFlags(['--phone-https' => 'local-ca']));
    $state = $fixture->state();

    expect($code)->toBe(0, json_encode($json))
        ->and($state->mode())->toBe('local')
        ->and($state->setting('lan_address'))->toBe('172.17.0.1')
        ->and($state->setting('phone_https'))->toBe('local-ca')
        ->and($state->setting('edge_subnet'))->toStartWith('10.213.')
        ->and($json['data']['url'])->toBe('https://172.17.0.1')
        ->and($json['data']['port'])->toBe(8080)
        ->and($json['data']['phone_https'])->toMatchArray(['mode' => 'local-ca', 'trust_url' => 'http://172.17.0.1/phone-trust', 'root_sha256' => LocalCaFixture::fingerprint($root)])
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('local_ca_trust', 'lan_exposed', 'url_changed_phone_data')
        ->and($fixture->directory->read('runtime/local-ca-root.crt'))->toBe(trim($root)."\n")
        ->and($fixture->directory->read('edge/Caddyfile'))->toContain('default_sni "172.17.0.1"')
        ->and($log->calls)->toContain('cat root', 'https https://172.17.0.1/up', 'http 172.17.0.1')
        ->and(array_map(static fn (ContainerSpec $spec): array => $spec->ports, $fixture->runSpecs('true')))->toContain(['172.17.0.1:80:8080'], ['172.17.0.1:443:8080']);
});

it('takes --lan-address, and refuses an install without any LAN address (exit 2)', function (): void {
    $fixture = new InstanceCommandFixture;
    localCaEdge($fixture);
    [$none, $noneJson] = $fixture->run('fsa:install', InstanceCommandFixture::installFlags(['--phone-https' => 'local-ca']));
    [$code, $json] = $fixture->run('fsa:install', InstanceCommandFixture::installFlags(['--phone-https' => 'local-ca', '--lan-address' => PhoneFixtures::LAN]));

    expect($none)->toBe(2)->and($noneJson['errors'][0]['code'])->toBe('lan_address_required')
        ->and($code)->toBe(0, json_encode($json))
        ->and($json['data']['url'])->toBe('https://'.PhoneFixtures::LAN);
});

it('points a repeated install with --phone-https to config set and fsa tunnel (exit 2)', function (): void {
    $fixture = new InstanceCommandFixture;
    $fixture->run('fsa:install', InstanceCommandFixture::installFlags());

    [$code, $json] = $fixture->run('fsa:install', InstanceCommandFixture::installFlags(['--phone-https' => 'local-ca', '--lan-address' => PhoneFixtures::LAN]));

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe('use_config_set')
        ->and($json['data']['flags'])->toContain(['flag' => '--phone-https', 'key' => 'phone-https'], ['flag' => '--lan-address', 'key' => 'lan-address'])
        ->and($json['errors'][0]['hint'])->toContain('fsa tunnel start|stop');
});

it('fails the preflight when 80 or 443 is taken on the LAN address (exit 3)', function (): void {
    $fixture = new InstanceCommandFixture;
    $fixture->takenPorts = [443];

    [$code, $json] = $fixture->run('fsa:install', InstanceCommandFixture::installFlags(['--phone-https' => 'local-ca', '--lan-address' => PhoneFixtures::LAN]));
    $ports = collect($json['data']['checks'])->firstWhere('key', 'ports');

    expect($code)->toBe(3)
        ->and($ports['summary'])->toContain('443')
        ->and($ports['details']['lan'])->toMatchArray(['address' => PhoneFixtures::LAN, 'taken' => [443]])
        ->and($fixture->directory->hasState())->toBeFalse();
});

it('turns the local CA on with config set: --yes for the URL, free ports, no demo password, then the root', function (): void {
    $fixture = new TunnelFixture;
    $fixture->config->instance->facts(['lanAddresses' => [PhoneFixtures::LAN]]);
    $log = localCaEdge($fixture);

    [$unconfirmed] = $fixture->config->set('phone-https', 'local-ca');
    $fixture->demoDefaults = ['worker@demo.test'];
    [$refused, $refusedJson] = $fixture->config->set('phone-https', 'local-ca', ['--yes' => true]);
    $fixture->demoDefaults = [];
    [$code, $json] = $fixture->config->set('phone-https', 'local-ca', ['--yes' => true]);
    [$again, $againJson] = $fixture->config->set('phone-https', 'local-ca', ['--yes' => true]);

    expect($unconfirmed)->toBe(6)
        ->and($refused)->toBe(3)->and($refusedJson['errors'][0]['code'])->toBe('default_password_in_use')
        ->and($refusedJson['errors'][0]['hint'])->toContain('fsa demo --rotate-passwords')
        ->and($code)->toBe(0, json_encode($json))
        ->and($json['data']['url'])->toBe('https://'.PhoneFixtures::LAN)
        ->and($json['data']['phone_https'])->toMatchArray(['mode' => 'local-ca', 'lan_address' => PhoneFixtures::LAN, 'lan_address_current' => true])
        ->and($fixture->config->state()->setting('edge_subnet'))->toStartWith('10.213.')
        ->and($log->calls)->toContain('cat root')
        ->and($again)->toBe(0)->and($againJson['changed'])->toBeFalse();
});

it('turns the local CA off: loopback URL, edge gone, the CA key deleted, the root gone from the runtime', function (): void {
    $fixture = new TunnelFixture;
    $fixture->config->instance->facts(['lanAddresses' => [PhoneFixtures::LAN]]);
    $log = localCaEdge($fixture);
    $fixture->config->set('phone-https', 'local-ca', ['--yes' => true]);

    [$code, $json] = $fixture->config->set('phone-https', 'off', ['--yes' => true]);
    $state = $fixture->config->state();

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['url'])->toBe('http://localhost:8080')
        ->and($json['data']['phone_https'])->toBe(['mode' => null, 'ca_deleted' => true])
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('local_ca_deleted')
        ->and($state->setting('phone_https'))->toBeNull()
        ->and($state->setting('lan_address'))->toBe(PhoneFixtures::LAN)
        ->and($log->calls)->toContain('rm -rf '.LocalCaStore::CA_DIRECTORY.' '.LocalCaStore::CERTIFICATES)
        ->and($fixture->config->instance->directory->exists('runtime/local-ca-root.crt'))->toBeFalse()
        ->and($fixture->config->instance->directory->read('compose.yaml'))->not->toContain('"edge"');
});

it('reverts when the edge does not serve the local CA (exit 5)', function (): void {
    $fixture = new TunnelFixture;
    $fixture->config->instance->facts(['lanAddresses' => [PhoneFixtures::LAN]]);
    localCaEdge($fixture, edgeFails: true);

    [$code, $json] = $fixture->config->set('phone-https', 'local-ca', ['--yes' => true]);

    expect($code)->toBe(5, json_encode($json))
        ->and($fixture->config->state()->setting('phone_https'))->toBeNull()
        ->and($fixture->config->instance->directory->exists('runtime/local-ca-root.crt'))->toBeFalse();
});

it('re-issues for a new LAN address with config set lan-address, and only records it while the CA is off', function (): void {
    $fixture = new TunnelFixture;
    $fixture->config->instance->facts(['lanAddresses' => [PhoneFixtures::LAN, '192.168.1.30']]);
    localCaEdge($fixture);
    [$recorded] = $fixture->config->set('lan-address', '192.168.1.30');
    $offState = $fixture->config->state();
    $fixture->config->set('phone-https', 'local-ca', ['--yes' => true]);
    [$moved, $movedJson] = $fixture->config->set('lan-address', PhoneFixtures::LAN, ['--yes' => true]);
    [$bad, $badJson] = $fixture->config->set('lan-address', '127.0.0.1', ['--yes' => true]);

    expect($recorded)->toBe(0)
        ->and($offState->setting('lan_address'))->toBe('192.168.1.30')
        ->and($offState->url())->toBeNull()
        ->and($moved)->toBe(0, json_encode($movedJson))
        ->and($movedJson['data']['url'])->toBe('https://'.PhoneFixtures::LAN)
        ->and($fixture->config->instance->directory->read('edge/Caddyfile'))->toContain('"https://'.PhoneFixtures::LAN.'"')
        ->and($bad)->toBe(2)->and($badJson['errors'][0]['code'])->toBe('invalid_value');
});

it('refuses phone-https values it does not take, and the local CA next to a tunnel or outside local mode (R61)', function (): void {
    $fixture = new TunnelFixture;
    [$tunnel, $tunnelJson] = $fixture->config->set('phone-https', 'tunnel', ['--yes' => true]);
    [$junk, $junkJson] = $fixture->config->set('phone-https', 'pigeon', ['--yes' => true]);
    $fixture->tunnel('start');
    [$beside, $besideJson] = $fixture->config->set('phone-https', 'local-ca', ['--yes' => true]);

    expect($tunnel)->toBe(2)->and($tunnelJson['errors'][0]['code'])->toBe('use_tunnel_command')
        ->and($junk)->toBe(2)->and($junkJson['errors'][0]['code'])->toBe('invalid_value')
        ->and($beside)->toBe(2)->and($besideJson['errors'][0]['code'])->toBe('invalid_combination');
});

it('reports a LAN address that is no longer the host\'s in doctor and status, with the re-issue command', function (): void {
    $fixture = new TunnelFixture;
    $fixture->config->instance->facts(['lanAddresses' => [PhoneFixtures::LAN]]);
    localCaEdge($fixture);
    $fixture->config->set('phone-https', 'local-ca', ['--yes' => true]);
    $fixture->config->instance->facts(['lanAddresses' => ['192.168.7.7']]);

    [, $doctor] = $fixture->config->instance->run('fsa:doctor');
    [, $status] = $fixture->config->instance->run('fsa:status');
    $check = collect($doctor['data']['checks'])->firstWhere('key', 'lan_address');

    expect($check['status'])->toBe('warn')
        ->and($check['hint'])->toContain('fsa config set lan-address 192.168.7.7 --yes')
        ->and($status['data']['phone_https']['lan_address_current'])->toBeFalse()
        ->and(collect($status['warnings'])->pluck('code')->all())->toContain('lan_address_changed');
});
