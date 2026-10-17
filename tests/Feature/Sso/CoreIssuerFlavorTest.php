<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Enums\OidcFlavor;
use App\Domain\Identity\Sso\Login\OidcProfileFactory;
use App\Domain\Identity\Sso\Providers\OidcSettings;
use App\Domain\Identity\Tenancy\TenantContext;
use SocialiteProviders\Manager\OAuth2\User as OAuthUser;
use Tests\Support\Sso\FakeOidcIdp;

/*
 * Gate review M7, finding 2: a generic OIDC configuration pointing at Google or Microsoft would skip the
 * Google `hd` and the Entra `tid` rule and accept any account of that IdP. It is refused at runtime.
 */
const CORE_FLAVOR_TENANT = '6f1d2c3b-4a59-4e8f-9b0a-1c2d3e4f5a6b';

beforeEach(function (): void {
    $this->reports = FakeOidcIdp::spyReporter();
    $this->org = makeOrganization(name: 'Acme GmbH');
    FakeOidcIdp::domain($this->org, 'acme.example');
});

it('refuses a generic configuration on a Google or Microsoft host before any request', function (string $issuer, array $config): void {
    $idp = FakeOidcIdp::bind($issuer);
    $provider = $idp->provider($this->org, [], ['flavor' => 'generic', 'issuer' => $issuer, ...$config]);

    $this->get(FakeOidcIdp::startUrl($provider))->assertRedirect('/app/login?sso_error=provider_unavailable');

    expect($idp->requests)->toBe([])
        ->and(Activity::query()->where('event', 'auth.sso_failed')->sole()->properties['misconfiguration'])->toBe('config')
        ->and($this->reports->getArrayCopy())->toBe(['config']);
})->with([
    'google' => ['https://accounts.google.com', []],
    'google without scheme' => ['accounts.google.com', []],
    'entra v2' => ['https://login.microsoftonline.com/'.CORE_FLAVOR_TENANT.'/v2.0', []],
    'entra v1' => ['https://sts.windows.net/'.CORE_FLAVOR_TENANT.'/', []],
    'google discovery behind another issuer' => ['https://idp.example.test/realms/acme', ['discovery_url' => 'https://accounts.google.com/.well-known/openid-configuration']],
]);

it('names the flavor a host requires', function (string $url, ?OidcFlavor $flavor): void {
    expect(OidcSettings::requiredFlavor($url))->toBe($flavor);
})->with([
    ['https://accounts.google.com', OidcFlavor::Google],
    ['ACCOUNTS.GOOGLE.COM.', OidcFlavor::Google],
    ['https://login.microsoftonline.com/common/v2.0', OidcFlavor::Entra],
    ['https://login.microsoft.com/x', OidcFlavor::Entra],
    ['https://login.windows.net/x', OidcFlavor::Entra],
    ['https://sts.windows.net/x/', OidcFlavor::Entra],
    ['https://eu.login.microsoftonline.com/x', OidcFlavor::Entra],
    ['https://keycloak.example.test/realms/acme', null],
    ['https://accounts.google.com.evil.example', null],
]);

it('refuses a Google or Microsoft token under a non-matching flavor (defense in depth)', function (string $iss): void {
    $provider = app(TenantContext::class)->run($this->org, fn (): IdentityProvider => IdentityProvider::factory()->active()->create());
    $settings = new OidcSettings(OidcFlavor::Generic, 'https://idp.example.test', 'https://idp.example.test', null, 'client', ['openid'], 'groups', false);
    $user = (new OAuthUser)->setRaw(['sub' => 'g-1', 'iss' => $iss, 'email' => 'jane@acme.example', 'email_verified' => true])
        ->map(['id' => 'g-1', 'email' => 'jane@acme.example']);

    expect(fn () => app(OidcProfileFactory::class)->make($provider, $settings, $user))
        ->toThrow(InvalidArgumentException::class, OidcSettings::CONFIG_ERROR);
})->with(['https://accounts.google.com', 'https://login.microsoftonline.com/'.CORE_FLAVOR_TENANT.'/v2.0']);
