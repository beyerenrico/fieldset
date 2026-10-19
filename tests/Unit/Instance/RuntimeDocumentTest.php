<?php

declare(strict_types=1);

use App\Domain\Instance\Runtime\InvalidRuntimeState;
use App\Domain\Instance\Runtime\RuntimeDocument;
use App\Domain\Instance\Runtime\TunnelState;
use Carbon\CarbonImmutable;
use Tests\Support\Instance\RuntimeFixture;

/*
 * Plan U10 / KTD11: the app's strict grammar of runtime.json. An invalid file never reaches the app as state; the
 * reader falls back instead (RuntimeStateReaderTest).
 */

it('parses a complete document', function (): void {
    $state = RuntimeDocument::parse(RuntimeFixture::document([
        'instance_url' => 'https://Forms.Example/',
        'extra_hosts' => ['www.forms.example', '192.168.1.20', '2001:db8::1'],
        'tunnel' => ['hostname' => RuntimeFixture::TUNNEL, 'expires_at' => '2026-10-18T16:00:00Z', 'peer' => RuntimeFixture::PEER, 'admin' => true],
        'gate' => ['raised' => true, 'operation' => 'upgrade', 'since' => '2026-10-18T12:00:00+02:00', 'smoke_token' => RuntimeFixture::TOKEN],
    ]));

    expect($state->instanceUrl)->toBe('https://Forms.Example')
        ->and($state->localUrl)->toBe('http://localhost:8080')
        ->and($state->extraHosts)->toBe(['www.forms.example', '192.168.1.20', '2001:db8::1'])
        ->and($state->tunnel->hostname)->toBe(RuntimeFixture::TUNNEL)
        ->and($state->tunnel->expiresAt?->toIso8601ZuluString())->toBe('2026-10-18T16:00:00Z')
        ->and($state->tunnel->peer)->toBe(RuntimeFixture::PEER)
        ->and($state->tunnel->admin)->toBeTrue()
        ->and($state->gate->raised)->toBeTrue()
        ->and($state->gate->operation)->toBe('upgrade')
        ->and($state->gate->since?->toIso8601ZuluString())->toBe('2026-10-18T10:00:00Z')
        ->and($state->gate->smokeToken)->toBe(RuntimeFixture::TOKEN);
});

it('accepts the minimal document of a newer minor version and ignores unknown keys', function (): void {
    $state = RuntimeDocument::parse([
        'schema_version' => '1.7',
        'instance_url' => 'http://[::1]:8080/forms',
        'gate' => ['raised' => false],
        'mode' => 'local',
    ]);

    expect($state->instanceUrl)->toBe('http://[::1]:8080/forms')
        ->and($state->localUrl)->toBeNull()
        ->and($state->extraHosts)->toBe([])
        ->and($state->tunnel)->toEqual(TunnelState::none())
        ->and($state->gate->raised)->toBeFalse()
        ->and($state->gate->smokeToken)->toBeNull();
});

it('refuses a document that is not a JSON object or has another major version', function (mixed $document, string $problem): void {
    expect(fn () => RuntimeDocument::parse($document))->toThrow(InvalidRuntimeState::class, $problem);
})->with([
    'list' => [[1, 2], 'not a JSON object'],
    'string' => ['runtime', 'not a JSON object'],
    'no schema' => [['instance_url' => 'http://localhost', 'gate' => ['raised' => false]], 'schema_version is missing'],
    'major 2' => [RuntimeFixture::document(['schema_version' => '2.0']), 'schema_version 2.0 is not supported'],
    'trailing newline' => [RuntimeFixture::document(['schema_version' => "1.0\n"]), 'malformed'],
]);

it('refuses a tunnel host outside trycloudflare.com', function (mixed $hostname): void {
    $document = RuntimeFixture::document(['tunnel' => ['hostname' => $hostname, 'expires_at' => '2026-10-18T16:00:00Z']]);

    expect(fn () => RuntimeDocument::parse($document))->toThrow(InvalidRuntimeState::class, 'tunnel.hostname');
})->with([
    'other domain' => 'evil.example',
    'suffix trick' => 'quiet.trycloudflare.com.evil.example',
    'subdomain' => 'a.b.trycloudflare.com',
    'trailing newline' => "quiet.trycloudflare.com\n",
    'uppercase' => 'Quiet.trycloudflare.com',
    'wildcard' => '*.trycloudflare.com',
    'not a string' => 42,
]);

it('refuses wildcards and malformed extra hosts', function (mixed $hosts): void {
    expect(fn () => RuntimeDocument::parse(RuntimeFixture::document(['extra_hosts' => $hosts])))
        ->toThrow(InvalidRuntimeState::class, 'extra_hosts');
})->with([
    'wildcard' => [['*.forms.example']],
    'trailing dot' => [['forms.example.']],
    'space' => [['forms example']],
    'numeric non-IP' => [['999.1.1.1']],
    'port' => [['forms.example:8443']],
    'not a list' => ['forms.example'],
    'object' => [['a' => 'forms.example']],
    'too many' => [array_map(static fn (int $i): string => "host{$i}.example", range(1, 21))],
]);

it('refuses URLs that are not plain http(s) URLs', function (mixed $url): void {
    expect(fn () => RuntimeDocument::parse(RuntimeFixture::document(['instance_url' => $url])))
        ->toThrow(InvalidRuntimeState::class, 'instance_url');
})->with([
    'missing' => null,
    'ftp' => 'ftp://forms.example',
    'userinfo' => 'https://user:pass@forms.example',
    'query' => 'https://forms.example/?next=evil',
    'fragment' => 'https://forms.example/#x',
    'wildcard' => 'https://*.forms.example',
    'newline' => "https://forms.example\n",
    'space' => 'https://forms example',
    'no host' => 'https:///path',
]);

it('refuses a gate, tunnel or local URL that breaks the grammar', function (array $overrides, string $problem): void {
    expect(fn () => RuntimeDocument::parse(array_replace(RuntimeFixture::document(), $overrides)))->toThrow(InvalidRuntimeState::class, $problem);
})->with([
    'no gate' => [['gate' => null], 'gate.raised is missing'],
    'raised not bool' => [['gate' => ['raised' => 'yes']], 'gate.raised is missing'],
    'operation newline' => [['gate' => ['raised' => true, 'operation' => "upgrade\n"]], 'gate.operation'],
    'short token' => [['gate' => ['raised' => true, 'smoke_token' => 'abc']], 'gate.smoke_token'],
    'token with quote' => [['gate' => ['raised' => true, 'smoke_token' => str_repeat('a', 40).'"']], 'gate.smoke_token'],
    'since not ISO' => [['gate' => ['raised' => true, 'since' => 'yesterday']], 'gate.since'],
    'tunnel not object' => [['tunnel' => 'quiet.trycloudflare.com'], 'tunnel must be an object'],
    'hostname without expiry' => [['tunnel' => ['hostname' => RuntimeFixture::TUNNEL]], 'tunnel.expires_at is required'],
    'hostname without peer' => [['tunnel' => ['hostname' => RuntimeFixture::TUNNEL, 'expires_at' => '2026-10-18T16:00:00Z']], 'tunnel.peer is required'],
    'peer not an IP' => [['tunnel' => ['peer' => 'cloudflared']], 'tunnel.peer'],
    'admin not bool' => [['tunnel' => ['admin' => 'yes']], 'tunnel.admin'],
    'local URL' => [['local_url' => 'localhost:8080'], 'local_url'],
]);

it('knows when a tunnel is active, expired or absent', function (): void {
    $now = CarbonImmutable::parse('2026-10-18T12:00:00Z');
    $active = new TunnelState(RuntimeFixture::TUNNEL, $now->addHour(), RuntimeFixture::PEER);
    $expired = new TunnelState(RuntimeFixture::TUNNEL, $now->subSecond(), RuntimeFixture::PEER);

    expect($active->isActive($now))->toBeTrue()->and($active->isExpired($now))->toBeFalse()
        ->and($active->url($now))->toBe('https://'.RuntimeFixture::TUNNEL)
        ->and($expired->isActive($now))->toBeFalse()->and($expired->isExpired($now))->toBeTrue()
        ->and($expired->url($now))->toBeNull()
        ->and(TunnelState::none()->isExpired($now))->toBeFalse();
});

it('reads whether webhooks are paused after a move, and treats a missing flag as not paused', function (): void {
    expect(RuntimeDocument::parse(RuntimeFixture::document(['webhooks' => ['paused' => true]]))->webhooksPaused)->toBeTrue()
        ->and(RuntimeDocument::parse(RuntimeFixture::document(['webhooks' => ['paused' => false]]))->webhooksPaused)->toBeFalse()
        ->and(RuntimeDocument::parse(RuntimeFixture::document())->webhooksPaused)->toBeFalse()
        ->and(RuntimeDocument::parse(RuntimeFixture::document(['webhooks' => null]))->webhooksPaused)->toBeFalse();
});

it('refuses a webhooks entry that breaks the grammar', function (mixed $webhooks): void {
    expect(fn () => RuntimeDocument::parse(RuntimeFixture::document(['webhooks' => $webhooks])))->toThrow(InvalidRuntimeState::class, 'webhooks');
})->with([
    'not an object' => ['paused'],
    'paused missing' => [['since' => '2026-10-19T10:00:00Z']],
    'paused as string' => [['paused' => 'yes']],
    'paused as number' => [['paused' => 1]],
]);
