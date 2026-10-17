<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\User;
use Illuminate\Testing\TestResponse;
use LightSaml\SamlConstants;
use Tests\Support\Sso\FakeOidcIdp;
use Tests\Support\Sso\FakeSamlIdp;

/*
 * Gate review M7, finding 3: the SAML identity key must be stable. An email NameID can later belong to
 * someone else and a transient one changes per login, so only a persistent NameID or a configured stable
 * attribute (`subject_attribute`) keys an identity.
 */
beforeEach(function (): void {
    $this->reports = FakeOidcIdp::spyReporter();
    $this->org = makeOrganization(name: 'Acme GmbH');
    FakeOidcIdp::domain($this->org, 'acme.example');
    $this->saml = new FakeSamlIdp;
    $this->provider = $this->saml->provider($this->org);
});

/**
 * @param  array<string, string|list<string>>  $attributes
 * @param  array<string, mixed>  $options
 */
function coreSamlLogin(FakeSamlIdp $saml, IdentityProvider $provider, array $attributes, array $options = []): TestResponse
{
    $request = $saml->authnRequest((string) test()->get(FakeOidcIdp::startUrl($provider))->assertRedirect()->headers->get('Location'));
    $acs = test()->post($provider->samlAcsUrl(), [
        'SAMLResponse' => $saml->response($request, $provider->samlEntityId(), $attributes, $options),
        'RelayState' => $request['relay_state'],
    ])->assertStatus(303);

    return test()->get((string) $acs->headers->get('Location'));
}

it('asks for a persistent NameID by default', function (): void {
    $location = (string) $this->get(FakeOidcIdp::startUrl($this->provider))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $xml = (string) gzinflate((string) base64_decode(is_string($query['SAMLRequest'] ?? null) ? $query['SAMLRequest'] : '', true));

    expect($xml)->toContain('Format="'.SamlConstants::NAME_ID_FORMAT_PERSISTENT.'"');
});

it('signs in with a persistent NameID as the subject', function (): void {
    coreSamlLogin($this->saml, $this->provider, ['email' => 'jane@acme.example'])->assertRedirect('/app/login?sso=ok');

    expect(ExternalIdentity::query()->withoutTenant()->sole()->subject)->toBe(FakeSamlIdp::persistentId(['email' => 'jane@acme.example']));
});

it('refuses an email, transient or unspecified NameID as the key and reports the IdP configuration', function (?string $format): void {
    coreSamlLogin($this->saml, $this->provider, ['email' => 'jane@acme.example'], ['name_id' => 'jane@acme.example', 'name_id_format' => $format])
        ->assertRedirect('/app/login?sso_error=sso_failed');

    $this->assertGuest();
    expect(User::query()->where('email', 'jane@acme.example')->exists())->toBeFalse()
        ->and(ExternalIdentity::query()->withoutTenant()->count())->toBe(0)
        ->and($this->reports->getArrayCopy())->toBe(['saml_name_id'])
        ->and(Activity::query()->where('event', 'auth.sso_failed')->sole()->properties['misconfiguration'])->toBe('saml_name_id');
})->with([
    'email' => SamlConstants::NAME_ID_FORMAT_EMAIL,
    'transient' => SamlConstants::NAME_ID_FORMAT_TRANSIENT,
    'unspecified' => SamlConstants::NAME_ID_FORMAT_UNSPECIFIED,
    'no format' => null,
]);

it('keys the identity by a configured stable attribute, whatever the NameID', function (): void {
    $provider = $this->saml->provider(makeOrganization(name: 'Keyed GmbH'), [], ['subject_attribute' => 'uid']);
    FakeOidcIdp::domain($provider->organization, 'keyed.example');
    $email = ['name_id_format' => SamlConstants::NAME_ID_FORMAT_EMAIL];

    coreSamlLogin($this->saml, $provider, ['email' => 'ann@keyed.example', 'uid' => 'kc-7f3a'], ['name_id' => 'ann@keyed.example', ...$email])
        ->assertRedirect('/app/login?sso=ok');
    auth()->logout();
    // The person's email changes at the IdP; the stable attribute still finds the same account.
    FakeOidcIdp::domain($provider->organization, 'keyed-group.example');
    coreSamlLogin($this->saml, $provider, ['email' => 'ann@keyed-group.example', 'uid' => 'kc-7f3a'], ['name_id' => 'ann@keyed-group.example', ...$email])
        ->assertRedirect('/app/login?sso=ok');

    expect(ExternalIdentity::query()->withoutTenant()->sole()->subject)->toBe('kc-7f3a')
        ->and(User::query()->where('email', 'ann@keyed-group.example')->count())->toBe(1);
});

it('refuses an assertion without the configured subject attribute', function (): void {
    $provider = $this->saml->provider(makeOrganization(name: 'Keyed GmbH'), [], ['subject_attribute' => 'uid']);

    coreSamlLogin($this->saml, $provider, ['email' => 'ann@acme.example'])->assertRedirect('/app/login?sso_error=sso_failed');

    expect($this->reports->getArrayCopy())->toBe(['saml_subject']);
});
