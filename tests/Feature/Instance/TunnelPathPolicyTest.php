<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Membership;
use App\Domain\Instance\Runtime\TunnelPathPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\Support\Instance\RuntimeFixture;

require_once __DIR__.'/Support.php';

/*
 * Plan U10 / R65 (app side) / KTD12: the quick tunnel serves only the Field App, the API, sign-in and assets.
 * A request whose TCP peer is cloudflared is a tunnel request whatever Host or X-Forwarded-Host it carries.
 */

beforeEach(function (): void {
    registerRuntimeProbes();
});

afterEach(function (): void {
    RuntimeFixture::cleanup();
    Request::setTrustedHosts([]);
});

it('hides the operator surfaces and everything outside R65 from the tunnel', function (string $path): void {
    RuntimeFixture::install(RuntimeFixture::tunnel());

    runtimeRequest('http://localhost:8080'.$path, RuntimeFixture::PEER)->assertNotFound();
})->with([
    '/platform', '/platform/login', '/horizon', '/horizon/api/stats', '/docs/api', '/docs/api.json',
    '/admin', '/admin/login', '/', '/up', '/form-preview/', '/livewire-d7fa0a55/update', '/filament/exports/1/download',
    '/documents/links/abc', '/storage/x', '/_scramble/dev-tools/devtools.js',
]);

it('serves the Field App, the API, sign-in and assets through the tunnel', function (string $method, string $path, int $status): void {
    RuntimeFixture::install(RuntimeFixture::tunnel());

    runtimeRequest('http://localhost:8080'.$path, RuntimeFixture::PEER, ['Accept' => 'application/json'], $method)->assertStatus($status);
})->with([
    'Field App' => ['GET', '/app', 200],
    'Field App route' => ['GET', '/app/forms/12', 200],
    'API' => ['GET', '/api/v1/me', 401],
    'CSRF cookie' => ['GET', '/sanctum/csrf-cookie', 204],
    'sign-in' => ['POST', '/auth/login', 422],
    'sign-in link' => ['GET', '/login-link', 200],
    'missing asset' => ['GET', '/build/missing.js', 404],
]);

it('lets a signed invitation link through the tunnel', function (): void {
    RuntimeFixture::install(RuntimeFixture::tunnel());
    $membership = Membership::query()->where('user_id', memberOf($organization = makeOrganization())->id)
        ->where('organization_id', $organization->id)->firstOrFail();
    URL::forceRootUrl('https://'.RuntimeFixture::TUNNEL);
    URL::forceScheme('https');
    $link = URL::signedRoute('invitations.show', ['membership' => $membership->id]);

    $response = runtimeRequest(str_replace('https://'.RuntimeFixture::TUNNEL, 'http://localhost:8080', $link), RuntimeFixture::PEER);

    expect($link)->toStartWith('https://'.RuntimeFixture::TUNNEL.'/invitations/')
        ->and($response->status())->not->toBeIn([403, 404, 500, 503]);
});

it('opens /admin through the tunnel only with --tunnel-admin', function (): void {
    RuntimeFixture::install(RuntimeFixture::tunnel(admin: true));

    runtimeRequest('http://localhost:8080/admin/login', RuntimeFixture::PEER)->assertOk();
    runtimeRequest('http://localhost:8080/platform/login', RuntimeFixture::PEER)->assertNotFound();
    runtimeRequest('http://localhost:8080/horizon', RuntimeFixture::PEER)->assertNotFound();
});

it('ignores a forged X-Forwarded-Host from the tunnel peer', function (?string $proxies): void {
    config(['trustedproxy.proxies' => $proxies]);
    RuntimeFixture::install(RuntimeFixture::tunnel());

    runtimeRequest('http://localhost:8080/platform', RuntimeFixture::PEER, ['X-Forwarded-Host' => 'localhost', 'X-Forwarded-Proto' => 'http'])->assertNotFound();
    runtimeRequest('http://localhost:8080/api/_runtime-probe', RuntimeFixture::PEER, ['X-Forwarded-Host' => 'localhost', 'X-Forwarded-Port' => '8080'])
        ->assertOk()->assertJson(['host' => RuntimeFixture::TUNNEL, 'secure' => true, 'url' => 'https://'.RuntimeFixture::TUNNEL.'/admin']);
})->with(['peer not trusted' => null, 'peer trusted as proxy' => RuntimeFixture::PEER]);

it('applies the policy to the tunnel host from any peer', function (): void {
    RuntimeFixture::install(RuntimeFixture::tunnel());

    runtimeRequest('https://'.RuntimeFixture::TUNNEL.'/platform')->assertNotFound();
    runtimeRequest('https://'.RuntimeFixture::TUNNEL.'/app')->assertOk();
    // The local URL from loopback stays unrestricted during the tunnel.
    runtimeRequest('http://localhost:8080/platform/login')->assertOk();
});

it('fails closed on a tunnel host without a recorded peer, so a trusted cloudflared cannot forge the host', function (): void {
    config(['trustedproxy.proxies' => RuntimeFixture::PEER]);
    RuntimeFixture::install(['tunnel' => ['hostname' => RuntimeFixture::TUNNEL, 'expires_at' => '2099-01-01T00:00:00Z']]);

    runtimeRequest('https://'.RuntimeFixture::TUNNEL.'/platform/login', RuntimeFixture::PEER, ['X-Forwarded-Host' => 'localhost'])->assertStatus(503);
});

it('answers the tunnel peer with 503 while no tunnel host is recorded', function (): void {
    RuntimeFixture::install(['tunnel' => ['peer' => RuntimeFixture::PEER]]);

    runtimeRequest('http://localhost:8080/app', RuntimeFixture::PEER)->assertStatus(503)->assertHeader('Retry-After', (string) TunnelPathPolicy::RETRY_AFTER);
    runtimeRequest('http://localhost:8080/app')->assertOk();
});

it('recognizes the peer by address, IPv6 included, and nobody else', function (): void {
    RuntimeFixture::install(array_replace_recursive(RuntimeFixture::tunnel(), ['tunnel' => ['peer' => 'fd00:0:0:0::10']]));

    runtimeRequest('http://localhost:8080/platform/login', 'fd00::10')->assertNotFound();
    runtimeRequest('http://localhost:8080/platform/login', 'fd00::11')->assertOk();

    RuntimeFixture::install(RuntimeFixture::tunnel());
    runtimeRequest('http://localhost:8080/platform/login', '::ffff:'.RuntimeFixture::PEER, ['X-Forwarded-Host' => 'localhost'])->assertNotFound();
});
