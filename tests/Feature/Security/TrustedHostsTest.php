<?php

declare(strict_types=1);

use App\Http\Middleware\Security\TrustedHosts;
use Illuminate\Http\Request;

/* Host header allow-list (ADR 0040): reset and invitation links are built from the request host. */

beforeEach(function (): void {
    config(['app.url' => 'https://forms.example']);
    // Laravel applies TrustHosts outside the local and testing environments only.
    $this->app->detectEnvironment(fn (): string => 'production');
});

afterEach(function (): void {
    Request::setTrustedHosts([]);
});

it('lists the APP_URL host, loopback names and TRUSTED_HOSTS', function (): void {
    config(['security.trusted_hosts' => ' Forms.Example.ORG ,']);

    expect(TrustedHosts::patterns())->toBe([
        '^forms\.example$', '^localhost$', '^127\.0\.0\.1$', '^\[\:\:1\]$', '^\:\:1$', '^forms\.example\.org$',
    ]);
});

it('answers the APP_URL host and the container health check', function (): void {
    $this->get('https://forms.example/up')->assertOk();
    $this->get('http://localhost:8080/up')->assertOk();
});

it('refuses any other Host, so no link can point elsewhere', function (): void {
    $response = $this->get('https://evil.example/admin/login')->assertBadRequest();

    // The refusal still carries the security headers (SecurityHeaders falls back to APP_URL's origin).
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    $this->post('https://evil.example/forgot-password', ['email' => 'victim@example.com'])->assertBadRequest();
});

it('refuses a forged X-Forwarded-Host from a trusted proxy as well', function (): void {
    config(['trustedproxy.proxies' => '127.0.0.1']);

    $this->get('https://forms.example/up', ['X-Forwarded-Host' => 'evil.example'])->assertBadRequest();
    $this->get('https://forms.example/up', ['X-Forwarded-Host' => 'forms.example'])->assertOk();
});
