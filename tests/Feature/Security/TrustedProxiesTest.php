<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/* Trusted reverse proxies (ADR 0040): TRUSTED_PROXIES → config('trustedproxy.proxies'), X-Forwarded-* headers. */

beforeEach(function (): void {
    Route::get('/proxy-probe', fn (): array => [
        'url' => url('/admin'),
        'route' => route('field-app'),
        'asset' => asset('js/app.js'),
        'ip' => request()->ip(),
        'secure' => request()->isSecure(),
    ])->middleware('web');
});

/** A request as a TLS-terminating proxy forwards it (the test client's REMOTE_ADDR is 127.0.0.1). */
function viaProxy(): TestResponse
{
    return test()->get('/proxy-probe', [
        'X-Forwarded-For' => '203.0.113.7',
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'forms.example',
        'X-Forwarded-Port' => '443',
    ])->assertOk();
}

it('ignores forwarded headers by default', function (): void {
    viaProxy()->assertJson([
        'url' => 'http://localhost:8080/admin',
        'ip' => '127.0.0.1',
        'secure' => false,
    ])->assertHeaderMissing('Strict-Transport-Security');
});

it('honours forwarded scheme, host, port and client address from a trusted proxy', function (string $proxies): void {
    config(['trustedproxy.proxies' => $proxies]);

    viaProxy()->assertJson([
        'url' => 'https://forms.example/admin',
        'route' => 'https://forms.example/app',
        'asset' => 'https://forms.example/js/app.js',
        'ip' => '203.0.113.7',
        'secure' => true,
    ])->assertHeader('Strict-Transport-Security', 'max-age=31536000');
})->with([
    'single address' => '127.0.0.1',
    'CIDR list' => '10.0.0.0/8, 127.0.0.0/8',
    'any (proxy-only network)' => '*',
]);

it('ignores forwarded headers from an address that is not a listed proxy', function (): void {
    config(['trustedproxy.proxies' => '10.0.0.1']);

    viaProxy()->assertJson(['url' => 'http://localhost:8080/admin', 'ip' => '127.0.0.1', 'secure' => false]);
});

it('builds https asset URLs and Secure cookies on admin pages behind a trusted proxy', function (): void {
    config(['trustedproxy.proxies' => '127.0.0.1', 'session.secure' => false]);

    $response = $this->get('/admin/login', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'forms.example', 'X-Forwarded-Port' => '443'])
        ->assertOk();
    $xsrf = collect($response->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === 'XSRF-TOKEN');

    expect($response->getContent())->toContain('src="https://forms.example/livewire')->not->toContain('http://forms.example')
        ->and($xsrf?->isSecure())->toBeTrue()
        ->and($response->headers->get('Content-Security-Policy'))->toEndWith('upgrade-insecure-requests');
});
