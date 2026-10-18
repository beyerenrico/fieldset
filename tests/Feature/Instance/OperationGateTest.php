<?php

declare(strict_types=1);

use App\Domain\Instance\Runtime\OperationGate;
use App\Domain\Instance\State\RuntimeWriter;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\RuntimeFixture;
use Tests\Support\Instance\TemporaryInstance;

require_once __DIR__.'/Support.php';

/*
 * Plan U10 / R62 (app side) / KTD11 / KTD19: with the gate up, external requests get 503 and Retry-After; only the
 * operation's smoke token from the internal network and the loopback healthcheck pass. An unusable runtime file in
 * a fresh process fails closed.
 */

const GATE_EXTERNAL = '203.0.113.9';

const GATE_INTERNAL = '172.18.0.5';

const GATE_EDGE = '172.18.0.2';

afterEach(function (): void {
    RuntimeFixture::cleanup();
    TemporaryInstance::cleanup();
});

it('answers external requests with 503 and Retry-After while the gate is up', function (): void {
    RuntimeFixture::install(RuntimeFixture::raisedGate());

    runtimeRequest('/admin/login', GATE_EXTERNAL)->assertStatus(503)->assertHeader('Retry-After', (string) OperationGate::RETRY_AFTER)
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    runtimeRequest('/api/v1/me', GATE_EXTERNAL)->assertStatus(503)->assertHeader('Retry-After', '30')->assertJsonPath('error.code', 'server_error');
    runtimeRequest('/up', GATE_EXTERNAL)->assertStatus(503);
    runtimeRequest('/admin/login', GATE_INTERNAL)->assertStatus(503);
    // Loopback is no exemption except for the healthcheck.
    runtimeRequest('/admin/login')->assertStatus(503);
});

it('answers the gate\'s 503 with the scheme resolved for the host: HSTS on https, none on loopback http', function (): void {
    RuntimeFixture::install([...RuntimeFixture::domain('forms.example'), ...RuntimeFixture::raisedGate()]);

    runtimeRequest('https://forms.example/admin/login', GATE_EXTERNAL)->assertStatus(503)->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    $loopback = runtimeRequest('http://localhost:8080/admin/login', GATE_EXTERNAL)->assertStatus(503)->assertHeaderMissing('Strict-Transport-Security');

    expect($loopback->headers->get('Content-Security-Policy'))->not->toContain('upgrade-insecure-requests');
});

it('passes the container healthcheck from loopback', function (string $peer): void {
    RuntimeFixture::install(RuntimeFixture::raisedGate());

    runtimeRequest('http://localhost:8080/up', $peer)->assertOk();
})->with(['IPv4' => '127.0.0.1', 'IPv6' => '::1', 'IPv4-mapped' => '::ffff:127.0.0.1']);

it('passes the operation\'s smoke token only from an internal address', function (): void {
    RuntimeFixture::install(RuntimeFixture::raisedGate());
    $token = [OperationGate::SMOKE_HEADER => RuntimeFixture::TOKEN];

    runtimeRequest('/admin/login', GATE_INTERNAL, $token)->assertOk();
    runtimeRequest('/up', 'fd00::5', $token)->assertOk();
    runtimeRequest('/admin/login', GATE_EXTERNAL, $token)->assertStatus(503);
    runtimeRequest('/admin/login', GATE_INTERNAL, [OperationGate::SMOKE_HEADER => str_repeat('0', 64)])->assertStatus(503);
    runtimeRequest('/admin/login', GATE_INTERNAL, [OperationGate::SMOKE_HEADER => ''])->assertStatus(503);
});

it('judges the client behind a trusted edge by its forwarded address', function (): void {
    config(['trustedproxy.proxies' => GATE_EDGE]);
    RuntimeFixture::install(RuntimeFixture::raisedGate());
    $token = [OperationGate::SMOKE_HEADER => RuntimeFixture::TOKEN];

    runtimeRequest('/up', GATE_EDGE, [...$token, 'X-Forwarded-For' => GATE_EXTERNAL])->assertStatus(503);
    runtimeRequest('/up', GATE_EDGE, [...$token, 'X-Forwarded-For' => '172.18.0.9'])->assertOk();
});

it('lets no token through while the gate has none, and never through the tunnel', function (): void {
    RuntimeFixture::install(RuntimeFixture::raisedGate(null));
    runtimeRequest('/admin/login', GATE_INTERNAL, [OperationGate::SMOKE_HEADER => RuntimeFixture::TOKEN])->assertStatus(503);

    RuntimeFixture::install([...RuntimeFixture::raisedGate(), ...RuntimeFixture::tunnel()]);
    runtimeRequest('/app', RuntimeFixture::PEER, [OperationGate::SMOKE_HEADER => RuntimeFixture::TOKEN])->assertStatus(503);
    // The tunnel host is a tunnel request from any peer: no healthcheck exemption, and /up is outside R65 anyway.
    runtimeRequest('https://'.RuntimeFixture::TUNNEL.'/app', '127.0.0.1')->assertStatus(503);
    runtimeRequest('https://'.RuntimeFixture::TUNNEL.'/up', '127.0.0.1')->assertNotFound();
});

it('serves everything once the gate is lowered', function (): void {
    RuntimeFixture::install();

    runtimeRequest('/admin/login', GATE_EXTERNAL)->assertOk();
    runtimeRequest('/up', GATE_EXTERNAL)->assertOk();
});

it('fails closed in a fresh process that cannot use the configured runtime file', function (callable $break): void {
    $break(RuntimeFixture::directory());

    runtimeRequest('/admin/login', GATE_EXTERNAL)->assertStatus(503)->assertHeader('Retry-After', '30');
    runtimeRequest('/up')->assertOk();
})->with([
    'missing' => fn (): Closure => static function (string $directory): void {},
    'unreadable (a directory in its place)' => fn (): Closure => static function (string $directory): void {
        mkdir($directory.'/runtime.json');
    },
    'corrupt' => fn (): Closure => static fn (string $directory) => RuntimeFixture::write($directory, '{"gate": {"raised": false'),
    'tunnel host outside trycloudflare.com' => fn (): Closure => static fn (string $directory) => RuntimeFixture::write($directory, RuntimeFixture::document([
        'tunnel' => ['hostname' => 'evil.example', 'expires_at' => '2099-01-01T00:00:00Z'],
    ])),
    'wildcard extra host' => fn (): Closure => static fn (string $directory) => RuntimeFixture::write($directory, RuntimeFixture::document(['extra_hosts' => ['*.example']])),
]);

it('keeps the last good gate when the file breaks later in the same process', function (): void {
    $directory = RuntimeFixture::install();
    runtimeRequest('/admin/login', GATE_EXTERNAL)->assertOk();

    RuntimeFixture::write($directory, 'garbage');

    runtimeRequest('/admin/login', GATE_EXTERNAL)->assertOk();
});

it('works with the gate and token RuntimeWriter writes (ops to app contract)', function (): void {
    $instance = TemporaryInstance::directory();
    $state = ComposeFixtures::state(path: $instance->root);
    $writer = new RuntimeWriter($instance);
    config(['instance.runtime_path' => $instance->path('runtime')]);

    $token = $writer->raiseGate($state, 'upgrade');
    runtimeRequest('/admin/login', GATE_EXTERNAL)->assertStatus(503);
    runtimeRequest('/admin/login', GATE_INTERNAL, [OperationGate::SMOKE_HEADER => $token])->assertOk();

    $writer->lowerGate($state);
    runtimeRequest('/admin/login', GATE_EXTERNAL)->assertOk();
});
