<?php

declare(strict_types=1);

use App\Domain\Platform\Health\Checks\RuntimeCheck;
use App\Domain\Platform\Health\HealthStatus;
use App\Http\Middleware\Security\TrustedHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\Support\Instance\RuntimeFixture;

require_once __DIR__.'/Support.php';

/*
 * Plan U10 / R56 / KTD11: the app resolves its URL root, trusted hosts, HTTPS expectation, cookie security and
 * Sanctum stateful domains from runtime.json per request, without a restart, and exactly as before without it.
 */

beforeEach(function (): void {
    registerRuntimeProbes();
    $this->session = (string) config('session.cookie');
});

afterEach(function (): void {
    RuntimeFixture::cleanup();
    Request::setTrustedHosts([]);
});

it('behaves exactly as before without the runtime directory', function (): void {
    config(['instance.runtime_path' => null]);

    runtimeRequest('/api/_runtime-probe')->assertOk()->assertJson(['url' => 'http://localhost:8080/admin', 'app_url' => 'http://localhost:8080']);
    runtimeRequest('https://forms.example/api/_runtime-probe')->assertOk()
        ->assertJson(['url' => 'https://forms.example/admin', 'secure' => true])
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');

    expect(TrustedHosts::patterns())->toBe(['^localhost$', '^127\.0\.0\.1$', '^\[\:\:1\]$', '^\:\:1$'])
        ->and(runtimeCookie(runtimeRequest('/admin/login')->assertOk(), $this->session))->not->toBeNull();
});

it('links to a changed instance URL on the next request, without a restart', function (): void {
    $directory = RuntimeFixture::install(RuntimeFixture::domain('one.example'));

    runtimeRequest('https://one.example/api/_runtime-probe')->assertOk()->assertJson(['url' => 'https://one.example/admin']);

    RuntimeFixture::write($directory, RuntimeFixture::document(RuntimeFixture::domain('two.example')));

    runtimeRequest('https://two.example/api/_runtime-probe')->assertOk()
        ->assertJson(['url' => 'https://two.example/admin', 'route' => 'https://two.example/app']);
    // A host outside the runtime's allowed hosts (here only reachable because testing skips TrustHosts) links to
    // the instance URL, never to itself.
    runtimeRequest('http://other.example/api/_runtime-probe')->assertOk()->assertJson(['url' => 'https://two.example/admin']);
});

it('keeps localhost links for a localhost request during an active tunnel', function (): void {
    RuntimeFixture::install([...RuntimeFixture::tunnel(), 'extra_hosts' => ['forms.lan']]);

    runtimeRequest('http://localhost:8080/api/_runtime-probe')->assertOk()
        ->assertJson(['url' => 'http://localhost:8080/admin', 'secure' => false])
        ->assertHeaderMissing('Strict-Transport-Security');
    runtimeRequest('http://forms.lan:8080/api/_runtime-probe')->assertOk()->assertJson(['url' => 'http://forms.lan:8080/admin']);
    runtimeRequest('http://localhost:8080/api/_runtime-probe', RuntimeFixture::PEER)->assertOk()
        ->assertJson(['url' => 'https://'.RuntimeFixture::TUNNEL.'/admin', 'host' => RuntimeFixture::TUNNEL, 'secure' => true]);
    // The tunnel host is https even when a request for it arrives as plain http from another peer.
    runtimeRequest('http://'.RuntimeFixture::TUNNEL.'/api/_runtime-probe')->assertOk()
        ->assertJson(['url' => 'https://'.RuntimeFixture::TUNNEL.'/admin', 'secure' => false])
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    // Every loopback spelling keeps its own origin, so a sign-in on 127.0.0.1 is not sent to localhost's cookies.
    runtimeRequest('http://127.0.0.1:8080/api/_runtime-probe')->assertOk()->assertJson(['url' => 'http://127.0.0.1:8080/admin']);
    runtimeRequest('http://[::1]:8080/api/_runtime-probe')->assertOk()->assertJson(['url' => 'http://[::1]:8080/admin']);
    // Any other host links to the public URL, which is the tunnel while it is active.
    runtimeRequest('http://other.example/api/_runtime-probe')->assertOk()->assertJson(['url' => 'https://'.RuntimeFixture::TUNNEL.'/admin']);
});

it('keeps the last good URL when the file breaks or goes missing, never answers 500, and warns', function (): void {
    Log::spy();
    $directory = RuntimeFixture::install(RuntimeFixture::domain('good.example'));
    runtimeRequest('https://good.example/api/_runtime-probe')->assertOk();
    expect(app(RuntimeCheck::class)->run()->status)->toBe(HealthStatus::Ok);

    RuntimeFixture::write($directory, '{"instance_url": "https://evil.example"');
    runtimeRequest('https://good.example/api/_runtime-probe')->assertOk()->assertJson(['url' => 'https://good.example/admin']);
    $broken = app(RuntimeCheck::class)->run();

    unlink($directory.'/runtime.json');
    runtimeRequest('https://good.example/api/_runtime-probe')->assertOk()->assertJson(['url' => 'https://good.example/admin']);

    expect($broken->status)->toBe(HealthStatus::Warn)
        ->and($broken->details)->toContain('runtime.json is not valid JSON')
        ->and(app(RuntimeCheck::class)->run()->details)->toContain('runtime.json is missing or not accessible');
    Log::shouldHaveReceived('warning')->with('Instance runtime file unusable; keeping the last good value.', Mockery::type('array'))->twice();
});

it('answers an expired tunnel host with 503 and Retry-After, the local URL as usual, an unknown host with 400', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    RuntimeFixture::install(RuntimeFixture::tunnel('-1 minute'));

    runtimeRequest('https://'.RuntimeFixture::TUNNEL.'/app', RuntimeFixture::PEER)->assertStatus(503)->assertHeader('Retry-After', '300');
    runtimeRequest('https://'.RuntimeFixture::TUNNEL.'/api/v1/me', '203.0.113.9')->assertStatus(503)->assertHeader('Retry-After', '300')
        ->assertJsonPath('error.code', 'server_error');
    runtimeRequest('http://localhost:8080/up')->assertOk();
    runtimeRequest('https://evil.example/up')->assertBadRequest();
});

it('answers an active tunnel host when TrustHosts applies', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    RuntimeFixture::install([...RuntimeFixture::tunnel(), 'extra_hosts' => ['2001:db8::1']]);

    runtimeRequest('http://localhost:8080/app', RuntimeFixture::PEER)->assertOk();
    runtimeRequest('http://[2001:db8::1]:8080/up')->assertOk();
    expect(TrustedHosts::patterns())->toContain('^'.preg_quote(RuntimeFixture::TUNNEL, '/').'$', '^\[2001\:db8\:\:1\]$', '^2001\:db8\:\:1$');
});

it('makes session cookies Secure and __Host- exactly when the resolved scheme is https', function (): void {
    RuntimeFixture::install(RuntimeFixture::domain('forms.example'));

    $https = runtimeRequest('https://forms.example/admin/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    $session = runtimeCookie($https, '__Host-'.$this->session);
    // A plain-http request on loopback in the same browser session: no HSTS, no Secure, no prefix.
    $loopback = runtimeRequest('http://localhost:8080/admin/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');

    expect($session?->isSecure())->toBeTrue()
        ->and($session?->getPath())->toBe('/')
        ->and($session?->getDomain())->toBeNull()
        ->and(runtimeCookie($https, 'XSRF-TOKEN')?->isSecure())->toBeTrue()
        ->and(runtimeCookie($loopback, $this->session)?->isSecure())->toBeFalse()
        ->and(runtimeCookie($loopback, '__Host-'.$this->session))->toBeNull()
        ->and(runtimeCookie($loopback, 'XSRF-TOKEN')?->isSecure())->toBeFalse()
        ->and($loopback->headers->get('Content-Security-Policy'))->not->toContain('upgrade-insecure-requests');
});

it('keeps the plain cookie name when a session domain rules the __Host- prefix out', function (): void {
    config(['session.domain' => 'forms.example']);
    RuntimeFixture::install(RuntimeFixture::domain('forms.example'));

    $response = runtimeRequest('https://forms.example/admin/login')->assertOk();

    expect(runtimeCookie($response, $this->session)?->isSecure())->toBeTrue()
        ->and(runtimeCookie($response, '__Host-'.$this->session))->toBeNull();
});

it('pins the tunnel peer to https on the tunnel host, so links signed there verify there', function (): void {
    RuntimeFixture::install(RuntimeFixture::tunnel());
    config(['trustedproxy.proxies' => RuntimeFixture::PEER]);

    $signed = (string) runtimeRequest('http://localhost:8080/api/_runtime-probe', RuntimeFixture::PEER, ['X-Forwarded-Proto' => 'http', 'X-Forwarded-Port' => '8080'])
        ->assertOk()->json('signed');

    expect($signed)->toStartWith('https://'.RuntimeFixture::TUNNEL.'/api/_runtime-signed?signature=');
    runtimeRequest($signed, RuntimeFixture::PEER, ['X-Forwarded-Proto' => 'http', 'X-Forwarded-Host' => 'localhost'])->assertOk()->assertSee('signed ok');
});

it('adds the allowed hosts to Sanctum\'s stateful domains', function (): void {
    RuntimeFixture::install([...RuntimeFixture::tunnel(), 'extra_hosts' => ['forms.lan']]);

    runtimeRequest('http://forms.lan:8081/api/_runtime-probe')->assertOk();

    expect(config('sanctum.stateful'))->toContain(RuntimeFixture::TUNNEL, 'forms.lan', 'forms.lan:8081', 'localhost:8080', 'localhost');
});

it('gives the Field App on the tunnel host its cookie session on API calls', function (): void {
    RuntimeFixture::install(RuntimeFixture::tunnel());
    $api = fn (string $origin) => runtimeRequest('http://localhost:8080/api/v1/me', RuntimeFixture::PEER, ['Origin' => $origin, 'Accept' => 'application/json'])
        ->assertUnauthorized();

    $tunnel = $api('https://'.RuntimeFixture::TUNNEL);
    $foreign = $api('https://evil.example');

    expect(runtimeCookie($tunnel, '__Host-'.$this->session)?->isSecure())->toBeTrue()
        ->and(runtimeCookie($foreign, '__Host-'.$this->session))->toBeNull();
});
