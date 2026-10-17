<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\SsoMisconfigurationReporter;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Login\SsoLoginResponder;
use App\Domain\Identity\Tenancy\TenantContext;
use Tests\Support\Sso\FakeOidcIdp;

final class SpyMisconfigurationReporter implements SsoMisconfigurationReporter
{
    /** @var list<array{provider: string, reason: string, detail: string}> */
    public array $reports = [];

    public function report(IdentityProvider $p, string $reason, string $detail): void
    {
        $this->reports[] = ['provider' => $p->uuid, 'reason' => $reason, 'detail' => $detail];
    }
}

beforeEach(function (): void {
    $this->idp = FakeOidcIdp::bind();
    $this->reporter = new SpyMisconfigurationReporter;
    app()->instance(SsoMisconfigurationReporter::class, $this->reporter);
    $this->org = makeOrganization(name: 'Acme GmbH');
    FakeOidcIdp::domain($this->org, 'acme.example');
    $this->provider = $this->idp->provider($this->org);
});

function startOidcLogin(IdentityProvider $provider, SsoClient $client = SsoClient::App, ?string $hint = null): string
{
    return (string) test()->get(FakeOidcIdp::startUrl($provider, $client, $hint))->assertRedirect()->headers->get('Location');
}

it('redirects with state, nonce, PKCE S256, response_mode=query and the login hint', function (): void {
    $location = startOidcLogin($this->provider, SsoClient::App, 'jane@acme.example');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith($this->idp->issuer.'/protocol/openid-connect/auth?')
        ->and($query)->toMatchArray([
            'client_id' => FakeOidcIdp::CLIENT_ID, 'redirect_uri' => $this->provider->callbackUrl(), 'response_type' => 'code',
            'code_challenge_method' => 'S256', 'response_mode' => 'query', 'login_hint' => 'jane@acme.example',
        ])
        ->and($query['scope'])->toBe('openid email profile')
        ->and(strlen((string) $query['state']))->toBe(40)
        ->and(strlen((string) $query['nonce']))->toBe(40)
        ->and($query['code_challenge'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($query)->not->toHaveKey('prompt');
});

it('asks for a fresh login with force_reauth', function (): void {
    app(TenantContext::class)->run($this->org, fn () => $this->provider->forceFill(['force_reauth' => true])->save());

    parse_str((string) parse_url(startOidcLogin($this->provider), PHP_URL_QUERY), $query);

    expect($query['prompt'])->toBe('login');
});

it('refuses a callback whose state or nonce is replayed or forged', function (): void {
    $location = startOidcLogin($this->provider);
    $callback = $this->idp->authorize($location, ['sub' => 'kc-1', 'email' => 'jane@acme.example']);
    $this->get($callback)->assertRedirect('/app/login?sso=ok');
    auth()->logout();

    // The same callback again: flow, state and code are one-time.
    $this->get($callback)->assertRedirect('/app/login?sso_error=sso_failed');

    // A forged state in a fresh flow.
    $this->get($this->idp->authorize(startOidcLogin($this->provider), ['sub' => 'kc-1', 'email' => 'jane@acme.example'], ['state' => 'forged']))
        ->assertRedirect('/app/login?sso_error=sso_failed');

    // A token minted for an earlier flow (old nonce) in a fresh flow.
    $this->get($this->idp->authorize(startOidcLogin($this->provider), ['sub' => 'kc-1', 'email' => 'jane@acme.example', 'nonce' => 'stale-nonce']))
        ->assertRedirect('/app/login?sso_error=sso_failed');

    $this->assertGuest();
    expect($this->reporter->reports)->toBe([])
        ->and($this->provider->fresh()->status)->toBe(IdentityProviderStatus::Active);
});

it('rejects tokens with a foreign audience, issuer, expiry or signing key and reports the provider', function (array $claims, array $options, string $reason): void {
    $this->idp->login($this->provider, ['sub' => 'kc-2', 'email' => 'jane@acme.example', ...$claims], SsoClient::App, $options)
        ->assertRedirect('/app/login?sso_error=sso_failed');

    $this->assertGuest();
    $failure = Activity::query()->where('event', 'auth.sso_failed')->sole();
    expect($failure->properties['misconfiguration'])->toBe($reason === 'none' ? null : $reason)
        ->and($this->reporter->reports)->toHaveCount($reason === 'none' ? 0 : 1);
})->with([
    'audience' => [['aud' => 'someone-else'], [], 'audience'],
    'issuer' => [['iss' => 'https://evil.example.test'], [], 'issuer'],
    'expired' => [['exp' => time() - 3600, 'iat' => time() - 7200], [], 'none'],
    'foreign key with a known kid' => [[], ['key' => 'foreign', 'kid' => 'main-1'], 'signature'],
]);

it('accepts a rotated key by refetching the JWKS for an unknown kid', function (): void {
    $this->idp->login($this->provider, ['sub' => 'kc-3', 'email' => 'jane@acme.example'])->assertRedirect('/app/login?sso=ok');
    auth()->logout();
    $this->idp->rotateKeys();

    $this->idp->login($this->provider, ['sub' => 'kc-3', 'email' => 'jane@acme.example'])->assertRedirect('/app/login?sso=ok');

    expect($this->idp->requestCount('/jwks'))->toBe(2)
        ->and($this->idp->requestCount('/.well-known/openid-configuration'))->toBe(1);
});

it('sets error status and reports when the JWKS answers 500, and clears it after the next success', function (): void {
    $this->idp->failWith('/jwks', 500);

    $this->idp->login($this->provider, ['sub' => 'kc-4', 'email' => 'jane@acme.example'])->assertRedirect('/app/login?sso_error=provider_unavailable');

    $provider = $this->provider->fresh();
    expect($provider->status)->toBe(IdentityProviderStatus::Error)
        ->and($provider->last_error)->toStartWith('jwks: ')
        ->and($this->reporter->reports)->toHaveCount(1)
        ->and($this->reporter->reports[0]['reason'])->toBe('jwks')
        ->and(Activity::query()->where('event', 'sso.provider_status_changed')->sole()->properties['to'])->toBe('error');

    $this->idp = FakeOidcIdp::bind();
    $this->idp->login($provider, ['sub' => 'kc-4', 'email' => 'jane@acme.example'])->assertRedirect('/app/login?sso=ok');

    expect($provider->fresh())->status->toBe(IdentityProviderStatus::Active)->last_error->toBeNull()->last_success_at->not->toBeNull();
});

it('blocks an IdP whose host resolves to a private address (SSRF) and reports it', function (): void {
    $this->idp = FakeOidcIdp::bind('https://intranet.example.test/realms/acme', ['intranet.example.test' => ['10.0.0.5']]);
    $provider = app(TenantContext::class)->run($this->org, function (): IdentityProvider {
        $this->provider->forceFill(['config' => [...$this->provider->config, 'issuer' => 'https://intranet.example.test/realms/acme']])->save();

        return $this->provider;
    });

    $this->get(FakeOidcIdp::startUrl($provider))->assertRedirect('/app/login?sso_error=provider_unavailable');

    expect($this->idp->requests)->toBe([])
        ->and($this->reporter->reports[0]['reason'])->toBe('blocked_host')
        ->and($provider->fresh()->status)->toBe(IdentityProviderStatus::Error);
});

it('blocks plain http unless insecure IdPs are allowed (dev/CI)', function (): void {
    $this->idp = FakeOidcIdp::bind('http://keycloak:8080/realms/acme', ['keycloak' => ['172.18.0.4']]);
    app(TenantContext::class)->run($this->org, fn () => $this->provider->forceFill(['config' => [...$this->provider->config, 'issuer' => 'http://keycloak:8080/realms/acme']])->save());

    $this->get(FakeOidcIdp::startUrl($this->provider))->assertRedirect('/app/login?sso_error=provider_unavailable');

    config(['sso.allow_insecure_idp' => true]);
    $this->idp->login($this->provider->fresh(), ['sub' => 'kc-5', 'email' => 'jane@acme.example'])->assertRedirect('/app/login?sso=ok');
});

it('maps an IdP access_denied to sso_denied without reporting', function (): void {
    $this->get($this->idp->deny(startOidcLogin($this->provider)))->assertRedirect('/app/login?sso_error=sso_denied');

    expect($this->reporter->reports)->toBe([]);
});

it('sends admin-client failures to the Filament login with a flash', function (): void {
    $this->get($this->idp->deny(startOidcLogin($this->provider, SsoClient::Admin)))
        ->assertRedirect(url('/admin/login?sso_error=sso_denied'))
        ->assertSessionHas(SsoLoginResponder::ERROR_FLASH, 'sso_denied');
});

it('refuses logins through a draft or disabled provider', function (IdentityProviderStatus $status): void {
    app(TenantContext::class)->run($this->org, fn () => $this->provider->forceFill(['status' => $status])->save());

    $this->get($this->provider->redirectUrl(SsoClient::App))->assertRedirect('/app/login?sso_error=provider_unavailable');
})->with([IdentityProviderStatus::Draft, IdentityProviderStatus::Disabled]);

it('runs a test login for members.manage without signing in, shows the claims and activates a draft', function (): void {
    $admin = User::factory()->memberOf($this->org, SystemRole::Admin)->create();
    app(TenantContext::class)->run($this->org, fn () => $this->provider->forceFill(['status' => IdentityProviderStatus::Draft])->save());
    $this->actingAs($admin)->withHeader('Referer', url('/admin/'.$this->org->slug.'/sso'));

    $location = (string) $this->get($this->provider->redirectUrl(SsoClient::Admin, null, true))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $response = $this->get($this->idp->authorize($location, ['sub' => 'kc-6', 'email' => 'probe@acme.example', 'groups' => ['crew']]));

    $response->assertRedirect(url('/admin/'.$this->org->slug.'/sso'))->assertSessionHas(SsoLoginResponder::TEST_RESULT_FLASH);
    $result = session(SsoLoginResponder::TEST_RESULT_FLASH);
    expect($query['prompt'])->toBe('login')
        ->and($result['ok'])->toBeTrue()
        ->and($result['profile']['email'])->toBe('probe@acme.example')
        ->and($result['profile']['claims']['groups'])->toBe(['crew'])
        ->and(auth()->id())->toBe($admin->id)
        ->and(User::query()->where('email', 'probe@acme.example')->exists())->toBeFalse()
        ->and($this->provider->fresh()->status)->toBe(IdentityProviderStatus::Active);
});

it('forbids test logins without members.manage', function (): void {
    $this->actingAs(User::factory()->memberOf($this->org, SystemRole::Designer)->create());

    $this->get($this->provider->redirectUrl(SsoClient::Admin, null, true))->assertForbidden();
    auth()->logout();
    $this->get($this->provider->redirectUrl(SsoClient::Admin, null, true))->assertForbidden();
});

it('rejects an unknown client value and a callback without a flow', function (): void {
    $this->get(route('sso.redirect', ['provider' => $this->provider->uuid, 'client' => 'https://evil.example']))->assertNotFound();
    $this->get($this->provider->callbackUrl().'?code=x&state=y')->assertRedirect('/app/login?sso_error=sso_failed');
});
