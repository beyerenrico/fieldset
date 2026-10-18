<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\TunnelNetwork;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Tunnel\TunnelClock;
use Carbon\CarbonImmutable;
use Tests\Support\Instance\DemoFixtures;
use Tests\Support\Instance\PhoneFixtures;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\TunnelFixture;

/*
 * Plan U14 (R20, R29, R61, R65, AE6, KTD12; ADR 0057, ADR 0082): `fsa tunnel start|stop|renew` against scripted Docker.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-18T12:00:00Z');
    $this->fixture = new TunnelFixture;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    TemporaryInstance::cleanup();
});

it('starts the tunnel: cloudflared on its network, the hostname from /quicktunnel, 4 hours in runtime.json', function (): void {
    $mark = $this->fixture->config->mark();
    [$code, $json] = $this->fixture->tunnel('start');
    $state = $this->fixture->config->state();
    $subnet = $state->setting('tunnel_subnet');
    $runtime = $this->fixture->runtimeTunnel();

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['url'])->toBe('https://'.PhoneFixtures::HOSTNAME)
        ->and($json['data']['tunnel'])->toMatchArray(['hostname' => PhoneFixtures::HOSTNAME, 'admin' => false, 'state' => 'active'])
        ->and($state->setting('phone_https'))->toBe('tunnel')
        ->and($subnet)->toStartWith('10.215.')
        ->and($runtime)->toBe(['hostname' => PhoneFixtures::HOSTNAME, 'expires_at' => '2026-10-18T16:00:00Z', 'peer' => TunnelNetwork::peerAddress($subnet), 'admin' => false])
        ->and(collect($json['warnings'])->pluck('code')->all())->toBe(['tunnel_third_party', 'tunnel_expires', 'url_changed_phone_data'])
        ->and($this->fixture->tunnelContainer())->toBe('running')
        ->and($this->fixture->accountCalls)->toBe(['app:demo-passwords --check --json'])
        ->and($this->fixture->config->methodsSince($mark))->toContain('composeStop', 'composeUp')
        ->and(new OperationJournal($this->fixture->config->instance->directory)->current())->toBeNull()
        ->and($this->fixture->config->archivedJournal('tunnel')->context())->toBe(['action' => 'start', 'admin' => false]);
});

it('opens /admin through the tunnel only with --tunnel-admin, and refuses it on stop and renew', function (): void {
    [$code, $json] = $this->fixture->tunnel('start', ['--tunnel-admin' => true]);
    [$stopCode, $stopJson] = $this->fixture->tunnel('stop', ['--tunnel-admin' => true]);

    expect($code)->toBe(0)
        ->and($this->fixture->runtimeTunnel()['admin'])->toBeTrue()
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('tunnel_admin')
        ->and($stopCode)->toBe(2)
        ->and($stopJson['errors'][0]['code'])->toBe('invalid_flag');
});

it('needs --yes when organizations besides the demo exist (exit 6) and changes nothing without it', function (): void {
    $this->fixture->nonDemoOrganizations = 2;
    $mark = $this->fixture->config->mark();

    [$code, $json] = $this->fixture->tunnel('start', ['--yes' => false]);

    expect($code)->toBe(6)
        ->and($json['errors'][0]['code'])->toBe('confirmation_required')
        ->and($json['errors'][0]['message'])->toContain('2 organization(s) besides the demo')
        ->and($this->fixture->config->methodsSince($mark))->not->toContain('composeUp')
        ->and($this->fixture->config->state()->setting('phone_https'))->toBeNull()
        ->and($this->fixture->tunnel('start', ['--yes' => true])[0])->toBe(0);
});

it('refuses while an account that is not a demo account signs in with the demo password (exit 3)', function (): void {
    $this->fixture->otherDefaults = ['real@example.com'];
    $mark = $this->fixture->config->mark();

    [$code, $json] = $this->fixture->tunnel('start');

    expect($code)->toBe(3)
        ->and($json['errors'][0]['code'])->toBe('default_password_in_use')
        ->and($json['errors'][0]['hint'])->toContain('real@example.com')
        ->and($json['data']['users'])->toBe(['real@example.com'])
        ->and($this->fixture->config->methodsSince($mark))->not->toContain('composeUp');
});

it('rotates demo accounts still on the demo password first and lists their new passwords once (R29)', function (): void {
    $this->fixture->demoDefaults = DemoFixtures::EMAILS;

    [$code, $json] = $this->fixture->tunnel('start');

    expect($code)->toBe(0, json_encode($json))
        ->and($this->fixture->accountCalls)->toBe(['app:demo-passwords --check --json', 'app:demo-passwords --rotate --actor-uid='.posix_getuid().' --json'])
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('demo_passwords_rotated')
        ->and(collect($json['secrets'])->pluck('name')->all())->toContain('demo_password:worker@demo.test')
        ->and($json['data']['demo']['rotated'])->toBeTrue();
});

it('refuses a tunnel next to the local CA, in domain and in proxy mode (R61, exit 2)', function (string $setup): void {
    $directory = $this->fixture->config->instance->directory;
    $state = $directory->state();
    $directory->saveState(match ($setup) {
        'local CA' => $state->withSettings(['phone_https' => 'local-ca', 'lan_address' => PhoneFixtures::LAN, 'edge_subnet' => PhoneFixtures::EDGE_SUBNET]),
        'domain' => $state->withMode('domain')->withUrl('https://forms.example.com')->withSettings(['domain' => 'forms.example.com', 'edge_subnet' => PhoneFixtures::EDGE_SUBNET]),
        default => $state->withMode('proxy')->withUrl('https://forms.example.com')->withSettings(['proxy' => 'port']),
    });

    [$code, $json] = $this->fixture->tunnel('start');

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('invalid_combination');
})->with(['local CA', 'domain', 'proxy']);

it('restores the previous stack when cloudflared reports no hostname (exit 5)', function (): void {
    $this->fixture->hostname = null;

    [$code, $json] = $this->fixture->tunnel('start');

    expect($code)->toBe(5, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('tunnel_unavailable')
        ->and($json['data']['reverted'])->toBeTrue()
        ->and($this->fixture->config->state()->setting('phone_https'))->toBeNull()
        ->and($this->fixture->runtimeTunnel()['hostname'])->toBeNull()
        ->and($this->fixture->tunnelContainer())->toBeNull()
        ->and(new OperationJournal($this->fixture->config->instance->directory)->current())->toBeNull();
});

it('answers a second start with the running tunnel and changes nothing', function (): void {
    $this->fixture->tunnel('start');
    $mark = $this->fixture->config->mark();

    [$code, $json] = $this->fixture->tunnel('start');

    expect($code)->toBe(0)
        ->and($json['changed'])->toBeFalse()
        ->and($json['data']['tunnel']['state'])->toBe('active')
        ->and($this->fixture->config->methodsSince($mark))->not->toContain('composeUp');
});

it('renews a living tunnel for 4 more hours and refuses without one (exit 2)', function (): void {
    [$none, $noneJson] = $this->fixture->tunnel('renew');
    $this->fixture->tunnel('start');
    CarbonImmutable::setTestNow('2026-10-18T15:00:00Z');

    [$code, $json] = $this->fixture->tunnel('renew');

    expect($none)->toBe(2)->and($noneJson['errors'][0]['code'])->toBe('no_tunnel')
        ->and($code)->toBe(0, json_encode($json))
        ->and($this->fixture->runtimeTunnel()['expires_at'])->toBe('2026-10-18T19:00:00Z')
        ->and($json['data']['previous_expires_at'])->toBe('2026-10-18T16:00:00Z');
});

it('records a new hostname when cloudflared reports one on renew', function (): void {
    $this->fixture->tunnel('start');
    $this->fixture->hostname = 'calm-lake-42.trycloudflare.com';

    [$code, $json] = $this->fixture->tunnel('renew');

    expect($code)->toBe(0)
        ->and($this->fixture->runtimeTunnel()['hostname'])->toBe('calm-lake-42.trycloudflare.com')
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('tunnel_hostname_changed', 'url_changed_phone_data');
});

it('stops the tunnel: runtime cleared, cloudflared gone, the URL local again; a second stop changes nothing', function (): void {
    $this->fixture->tunnel('start');

    [$code, $json] = $this->fixture->tunnel('stop');
    [$again, $againJson] = $this->fixture->tunnel('stop');

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['url'])->toBe('http://localhost:8080')
        ->and($json['data']['previous']['hostname'])->toBe(PhoneFixtures::HOSTNAME)
        ->and($this->fixture->config->state()->setting('phone_https'))->toBeNull()
        ->and($this->fixture->config->state()->setting('tunnel_subnet'))->not->toBeNull()
        ->and($this->fixture->runtimeTunnel()['hostname'])->toBeNull()
        ->and($this->fixture->tunnelContainer())->toBeNull()
        ->and($again)->toBe(0)
        ->and($againJson['changed'])->toBeFalse();
});

it('reports an expired tunnel in status without changing anything (AE6)', function (): void {
    $this->fixture->tunnel('start');
    CarbonImmutable::setTestNow('2026-10-18T16:00:01Z');
    $directory = $this->fixture->config->instance->directory;
    $before = [$directory->read('state.json'), $directory->read('runtime/runtime.json')];
    $mark = $this->fixture->config->mark();

    [$code, $json] = $this->fixture->config->instance->run('fsa:status');

    expect($code)->toBe(0)
        ->and($json['data']['tunnel'])->toMatchArray(['state' => 'expired', 'hostname' => PhoneFixtures::HOSTNAME, 'url' => null])
        ->and($json['data']['phone_https'])->toBe(['mode' => 'tunnel'])
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('tunnel_expired')
        ->and([$directory->read('state.json'), $directory->read('runtime/runtime.json')])->toBe($before)
        ->and($this->fixture->config->methodsSince($mark))->not->toContain('composeUp', 'composeStop', 'removeNetwork')
        ->and($this->fixture->tunnelContainer())->toBe('running');
});

it('removes an expired tunnel before the next mutating command, which then runs (AE6)', function (string $command, array $parameters): void {
    $this->fixture->tunnel('start');
    CarbonImmutable::setTestNow('2026-10-18T16:00:01Z');

    [$code, $json] = $this->fixture->config->instance->run($command, $parameters);

    expect(collect($json['warnings'])->pluck('code')->all())->toContain('tunnel_removed')
        ->and($this->fixture->tunnelContainer())->toBeNull()
        ->and($this->fixture->runtimeTunnel()['hostname'])->toBeNull()
        ->and($this->fixture->config->state()->setting('phone_https'))->toBeNull()
        ->and(collect($this->fixture->config->archivedJournals('tunnel'))->map(fn ($record) => $record->context()['action'] ?? null)->all())->toContain('expire');
})->with([
    'tunnel renew' => ['fsa:tunnel', ['action' => 'renew']],
    'config set port' => ['fsa:config', ['action' => 'set', 'key' => 'port', 'value' => '8090', '--yes' => true]],
    'config set port, unconfirmed (the command fails, the removal stays)' => ['fsa:config', ['action' => 'set', 'key' => 'port', 'value' => '8090']],
]);

it('removes a tunnel whose cloudflared stopped on the next mutating command', function (): void {
    $this->fixture->tunnel('start');
    $this->fixture->config->instance->docker->stopContainer('7unne1');

    [, $statusJson] = $this->fixture->config->instance->run('fsa:status');
    [$code, $json] = $this->fixture->tunnel('start');

    expect($statusJson['data']['tunnel']['state'])->toBe('stopped')
        ->and(collect($statusJson['warnings'])->pluck('code')->all())->toContain('tunnel_stopped')
        ->and($code)->toBe(0, json_encode($json))
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('tunnel_removed')
        ->and($this->fixture->tunnelContainer())->toBe('running');
});

it('refuses an unknown action and works only on an installed instance', function (): void {
    [$code, $json] = $this->fixture->tunnel('open');

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('invalid_usage')
        ->and(TunnelClock::lifetime())->toBe(TunnelClock::LIFETIME_SECONDS);
});
