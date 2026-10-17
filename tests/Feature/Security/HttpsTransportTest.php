<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/* HSTS, upgrade-insecure-requests and Secure cookies behind HTTPS (BUILD_PROMPT §11, ADR 0040). */

function xsrfCookie(TestResponse $response): ?Cookie
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === 'XSRF-TOKEN') {
            return $cookie;
        }
    }

    return null;
}

it('sends HSTS for one year when APP_URL is https', function (): void {
    config(['app.url' => 'https://forms.example']);

    $response = $this->get('/admin/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');

    expect($response->headers->get('Content-Security-Policy'))->toEndWith('; upgrade-insecure-requests');
});

it('sends HSTS on a secure request even when APP_URL is http', function (): void {
    $this->get('https://localhost/admin/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('sends no HSTS and no upgrade-insecure-requests over plain http', function (): void {
    $response = $this->get('/admin/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');

    expect($response->headers->get('Content-Security-Policy'))->not->toContain('upgrade-insecure-requests');
});

it('applies the configured HSTS options', function (): void {
    config(['app.url' => 'https://forms.example', 'security.hsts' => ['max_age' => 600, 'include_subdomains' => true, 'preload' => true]]);

    $this->get('/up')->assertHeader('Strict-Transport-Security', 'max-age=600; includeSubDomains; preload');
});

it('can switch HSTS off or force it on', function (): void {
    config(['app.url' => 'https://forms.example', 'security.hsts.enabled' => false]);
    $this->get('/up')->assertHeaderMissing('Strict-Transport-Security');

    config(['app.url' => 'http://localhost', 'security.hsts.enabled' => true]);
    $this->get('/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('marks the session and XSRF cookies Secure, SameSite=Lax behind https', function (): void {
    config(['app.url' => 'https://forms.example', 'session.secure' => false]);

    $cookie = xsrfCookie($this->get('/admin/login')->assertOk());

    expect($cookie)->not->toBeNull()
        ->and($cookie?->isSecure())->toBeTrue()
        ->and($cookie?->getSameSite())->toBe('lax')
        ->and(config('session.secure'))->toBeTrue()
        ->and(config('session.http_only'))->toBeTrue();
});

it('leaves cookies non-Secure over plain http (local development)', function (): void {
    config(['session.secure' => false]);

    expect(xsrfCookie($this->get('/admin/login')->assertOk())?->isSecure())->toBeFalse();
});
