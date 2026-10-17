<?php

declare(strict_types=1);

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\HomeRealmDiscovery;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->org = makeOrganization(name: 'Acme GmbH');
    $this->tenant = app(TenantContext::class);
    $this->domain = $this->tenant->run($this->org, fn (): SsoDomain => SsoDomain::factory()->verified()->create(['domain' => 'acme.example']));
    $this->provider = $this->tenant->run($this->org, fn (): IdentityProvider => IdentityProvider::factory()->active()->create());
});

/** The discovery URL without its per-session start token (SsoStartLink), and the token. */
function discoveryUrlParts(string $url): array
{
    expect($url)->toMatch('/[?&]start=[A-Za-z0-9]{40}$/');

    return [(string) preg_replace('/&start=[A-Za-z0-9]{40}$/', '', $url), substr($url, -40)];
}

it('answers sso for a verified domain with an active provider, with the client and login hint in the URL', function (): void {
    $admin = $this->postJson('/auth/sso/discover', ['email' => 'Jane@ACME.example', 'client' => 'admin'])->assertOk();
    $admin->assertExactJson([
        'mode' => 'sso',
        'redirect_url' => $admin->json('redirect_url'),
        'organization' => 'Acme GmbH',
        'enforced' => false,
    ]);
    expect(discoveryUrlParts((string) $admin->json('redirect_url'))[0])->toBe($this->provider->redirectUrl(SsoClient::Admin, 'jane@acme.example'));

    $this->tenant->run($this->org, fn () => $this->provider->forceFill(['enforce_sso' => true])->save());
    $app = $this->postJson('/auth/sso/discover', ['email' => 'jane@acme.example'])->assertJsonPath('enforced', true);
    expect(discoveryUrlParts((string) $app->json('redirect_url'))[0])->toBe($this->provider->redirectUrl(SsoClient::App, 'jane@acme.example'));
});

it('keeps offering SSO while the provider is in error', function (): void {
    $this->tenant->run($this->org, fn () => $this->provider->forceFill(['status' => IdentityProviderStatus::Error])->save());

    $this->postJson('/auth/sso/discover', ['email' => 'jane@acme.example'])->assertJsonPath('mode', 'sso');
});

it('answers password for draft or disabled providers, unverified, unknown and sub-domains', function (string $email, ?IdentityProviderStatus $status): void {
    if ($status instanceof IdentityProviderStatus) {
        $this->tenant->run($this->org, fn () => $this->provider->forceFill(['status' => $status])->save());
    }
    $this->tenant->run(makeOrganization(name: 'Claimer'), fn () => SsoDomain::factory()->create(['domain' => 'claimed.example']));

    $this->postJson('/auth/sso/discover', ['email' => $email])->assertOk()->assertExactJson(['mode' => 'password']);
})->with([
    'draft provider' => ['jane@acme.example', IdentityProviderStatus::Draft],
    'disabled provider' => ['jane@acme.example', IdentityProviderStatus::Disabled],
    'unverified claim' => ['jane@claimed.example', null],
    'unknown domain' => ['jane@gmail.example', null],
    'sub-domain' => ['jane@eu.acme.example', null],
]);

it('gives the same answer whether or not an account exists', function (): void {
    User::factory()->create(['email' => 'known@acme.example']);
    User::factory()->create(['email' => 'known@gmail.example']);

    $known = $this->postJson('/auth/sso/discover', ['email' => 'known@acme.example'])->json();
    $unknown = $this->postJson('/auth/sso/discover', ['email' => 'unknown@acme.example'])->json();
    expect(array_diff_key($known, ['redirect_url' => 1]))->toBe(array_diff_key($unknown, ['redirect_url' => 1]));

    $this->postJson('/auth/sso/discover', ['email' => 'known@gmail.example'])->assertExactJson(['mode' => 'password']);
    $this->postJson('/auth/sso/discover', ['email' => 'unknown@gmail.example'])->assertExactJson(['mode' => 'password']);
});

it('validates the input and throttles', function (): void {
    $this->postJson('/auth/sso/discover', ['email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->postJson('/auth/sso/discover', ['email' => 'jane@acme.example', 'client' => 'https://evil.example'])->assertJsonValidationErrors('client');

    foreach (range(3, 20) as $_) {
        $this->postJson('/auth/sso/discover', ['email' => 'jane@acme.example'])->assertOk();
    }
    $this->postJson('/auth/sso/discover', ['email' => 'jane@acme.example'])->assertTooManyRequests();
});

it('normalizes internationalized domains to punycode', function (): void {
    $this->tenant->run($this->org, fn () => SsoDomain::factory()->verified()->create(['domain' => 'xn--mller-kva.example']));

    expect(app(HomeRealmDiscovery::class)->discover('jörg@müller.example', SsoClient::App)->isSso())->toBeTrue();
});
