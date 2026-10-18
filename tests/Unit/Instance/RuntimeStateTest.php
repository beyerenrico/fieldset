<?php

declare(strict_types=1);

use App\Domain\Instance\Runtime\HostName;
use App\Domain\Instance\Runtime\RuntimeDocument;
use App\Domain\Instance\Runtime\RuntimeState;
use Carbon\CarbonImmutable;
use Tests\Support\Instance\RuntimeFixture;

/* Plan U10 / KTD11: which hosts keep their own origin, which the app answers at all, and the public URL. */

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-10-18T12:00:00Z');
});

function runtimeStateWithTunnel(string $expiresAt): RuntimeState
{
    return RuntimeDocument::parse(RuntimeFixture::document([
        'extra_hosts' => ['forms.lan', '2001:db8::1'],
        'tunnel' => ['hostname' => RuntimeFixture::TUNNEL, 'expires_at' => $expiresAt, 'peer' => RuntimeFixture::PEER],
    ]));
}

it('allows the instance, local and extra hosts and the active tunnel, and uses the tunnel as public URL', function (): void {
    $state = runtimeStateWithTunnel('2026-10-18T14:00:00Z');

    expect($state->allowedHosts($this->now))->toBe(['localhost', 'forms.lan', '2001:db8::1', '127.0.0.1', '::1', RuntimeFixture::TUNNEL])
        ->and($state->knownHosts($this->now))->toBe(['localhost', 'forms.lan', '2001:db8::1', '127.0.0.1', '::1', RuntimeFixture::TUNNEL])
        ->and($state->isLocalHost('[::1]'))->toBeTrue()
        ->and($state->publicUrl($this->now))->toBe('https://'.RuntimeFixture::TUNNEL)
        ->and($state->isAllowedHost('[2001:DB8::1]', $this->now))->toBeTrue()
        ->and($state->isTunnelHost(strtoupper(RuntimeFixture::TUNNEL)))->toBeTrue()
        ->and($state->isLocalHost('LOCALHOST'))->toBeTrue()
        ->and($state->isInstanceHost('localhost'))->toBeTrue();
});

it('keeps an expired tunnel host known but not allowed, and falls back to the instance URL', function (): void {
    $state = runtimeStateWithTunnel('2026-10-18T11:59:59Z');

    expect($state->allowedHosts($this->now))->toBe(['localhost', 'forms.lan', '2001:db8::1', '127.0.0.1', '::1'])
        ->and($state->knownHosts($this->now))->toContain(RuntimeFixture::TUNNEL)
        ->and($state->isAllowedHost(RuntimeFixture::TUNNEL, $this->now))->toBeFalse()
        ->and($state->publicUrl($this->now))->toBe('http://localhost:8080');
});

it('builds a fail-closed fallback from APP_URL', function (): void {
    $state = RuntimeState::fallback('https://forms.example/');

    expect($state->instanceUrl)->toBe('https://forms.example')
        ->and(RuntimeDocument::parse(RuntimeFixture::document(['local_url' => 'http://forms.lan:8080']))->allowedHosts($this->now))
        ->toBe(['localhost', 'forms.lan'])
        ->and($state->gate->raised)->toBeTrue()
        ->and($state->gate->smokeToken)->toBeNull()
        ->and($state->allowedHosts($this->now))->toBe(['forms.example'])
        ->and($state->isLocalHost('localhost'))->toBeFalse();
});

it('normalizes host names and recognizes loopback', function (): void {
    expect(HostName::normalize(' [::1] '))->toBe('::1')
        ->and(HostName::fromUrl('https://Forms.Example:8443/x'))->toBe('forms.example')
        ->and(HostName::spellings('2001:db8::1'))->toBe(['2001:db8::1', '[2001:db8::1]'])
        ->and(HostName::spellings('forms.example'))->toBe(['forms.example'])
        ->and(array_map(HostName::isLoopback(...), ['localhost', 'app.localhost', '127.0.0.1', '127.8.9.1', '[::1]']))->each->toBeTrue()
        ->and(array_map(HostName::isLoopback(...), ['forms.example', '10.0.0.1', 'localhost.example', '::2', '']))->each->toBeFalse();
});

it('canonicalizes IP addresses, IPv4-mapped IPv6 included', function (): void {
    expect(HostName::canonicalIp('::ffff:172.30.0.10'))->toBe('172.30.0.10')
        ->and(HostName::canonicalIp('FD00:0:0:0::10'))->toBe('fd00::10')
        ->and(HostName::canonicalIp('127.0.0.1'))->toBe('127.0.0.1')
        ->and(HostName::canonicalIp('localhost'))->toBeNull()
        ->and(HostName::canonicalIp(''))->toBeNull()
        ->and(HostName::isLoopback('::ffff:127.0.0.1'))->toBeTrue();
});
