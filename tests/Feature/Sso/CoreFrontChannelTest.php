<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use LightSaml\SamlConstants;
use Tests\Support\Sso\FakeOidcIdp;
use Tests\Support\Sso\FakeSamlIdp;

/*
 * Gate review M7, finding 1: input the browser brings (the OIDC callback's error, a posted SAML response, a
 * code the token endpoint refuses) must never mark the provider as faulty, notify admins, or put free text
 * into the audit. Genuine back-channel faults still do.
 */
const CORE_FRONT_ATTACKER_TEXT = 'invalid_client: the secret expired, call +1-555-0100 now';

beforeEach(function (): void {
    $this->reports = FakeOidcIdp::spyReporter();
    $this->idp = FakeOidcIdp::bind();
    $this->org = makeOrganization(name: 'Acme GmbH');
    FakeOidcIdp::domain($this->org, 'acme.example');
    $this->provider = $this->idp->provider($this->org);
});

/** @return array<string, string> the authorization request's query */
function coreFrontAuthorization(IdentityProvider $provider): array
{
    parse_str((string) parse_url((string) test()->get(FakeOidcIdp::startUrl($provider))->assertRedirect()->headers->get('Location'), PHP_URL_QUERY), $query);

    return array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $query);
}

function coreFrontExpectNoProviderFault(object $test): void
{
    expect($test->reports->getArrayCopy())->toBe([])
        ->and($test->provider->fresh()->status)->toBe(IdentityProviderStatus::Active)
        ->and($test->provider->fresh()->last_error)->toBeNull()
        ->and(Activity::query()->get()->toJson())->not->toContain('+1-555-0100');
}

it('never counts a forged IdP error on the callback of a live flow as a provider fault', function (): void {
    $query = coreFrontAuthorization($this->provider);

    $this->get($this->provider->callbackUrl().'?'.http_build_query(['error' => 'invalid_client', 'error_description' => CORE_FRONT_ATTACKER_TEXT, 'state' => $query['state']]))
        ->assertRedirect('/app/login?sso_error=sso_failed');

    $this->assertGuest();
    coreFrontExpectNoProviderFault($this);
    expect(Activity::query()->where('event', 'auth.sso_failed')->sole()->properties->all())
        ->toMatchArray(['reason' => 'sso_failed', 'misconfiguration' => null, 'cause' => 'idp_error', 'detail' => 'idp_error']);
});

it('keeps a standard IdP error code in the audit and maps access_denied to sso_denied', function (): void {
    $query = coreFrontAuthorization($this->provider);

    $this->get($this->provider->callbackUrl().'?'.http_build_query(['error' => 'access_denied', 'error_description' => CORE_FRONT_ATTACKER_TEXT, 'state' => $query['state']]))
        ->assertRedirect('/app/login?sso_error=sso_denied');

    coreFrontExpectNoProviderFault($this);
    expect(Activity::query()->where('event', 'auth.sso_failed')->sole()->properties['cause'])->toBe('idp_error:access_denied');
});

it('checks the flow state before it honours an error, so a forged callback cannot burn the real flow', function (): void {
    $query = coreFrontAuthorization($this->provider);

    $this->get($this->provider->callbackUrl().'?'.http_build_query(['error' => 'invalid_client', 'error_description' => CORE_FRONT_ATTACKER_TEXT, 'state' => 'forged']))
        ->assertRedirect('/app/login?sso_error=sso_failed');

    coreFrontExpectNoProviderFault($this);
    expect(Activity::query()->where('event', 'auth.sso_failed')->sole()->properties['detail'])->toBe('oidc_flow_missing');

    $authorization = $this->idp->issuer.'/protocol/openid-connect/auth?'.http_build_query($query);
    $this->get($this->idp->authorize($authorization, ['sub' => 'kc-1', 'email' => 'jane@acme.example']))->assertRedirect('/app/login?sso=ok');
});

it('does not count a token endpoint refusing a code the browser brought as a provider fault', function (): void {
    $query = coreFrontAuthorization($this->provider);

    $this->get($this->provider->callbackUrl().'?'.http_build_query(['code' => 'forged-code', 'state' => $query['state']]))
        ->assertRedirect('/app/login?sso_error=sso_failed');

    coreFrontExpectNoProviderFault($this);
    expect(Activity::query()->where('event', 'auth.sso_failed')->sole()->properties->all())
        ->toMatchArray(['misconfiguration' => null, 'cause' => 'token_rejected']);
});

it('still reports a token endpoint that rejects the client credentials (back channel)', function (): void {
    $this->idp->failWith('/token', 401, ['error' => 'invalid_client']);

    $this->idp->login($this->provider, ['sub' => 'kc-2', 'email' => 'jane@acme.example'])->assertRedirect('/app/login?sso_error=sso_failed');

    expect($this->reports->getArrayCopy())->toBe(['invalid_client'])
        ->and($this->provider->fresh()->status)->toBe(IdentityProviderStatus::Error)
        ->and($this->provider->fresh()->last_error)->toStartWith('invalid_client: GuzzleHttp\Exception\ClientException: ');
});

it('audits an unsigned unsuccessful SAML status with a fixed code only', function (): void {
    $saml = new FakeSamlIdp;
    $this->provider = $saml->provider(makeOrganization(name: 'Saml GmbH'));
    $request = $saml->authnRequest((string) $this->get(FakeOidcIdp::startUrl($this->provider))->assertRedirect()->headers->get('Location'));
    $response = $saml->response($request, $this->provider->samlEntityId(), ['email' => 'jane@acme.example'], [
        'sign' => null, 'status' => SamlConstants::STATUS_RESPONDER, 'status_message' => CORE_FRONT_ATTACKER_TEXT,
    ]);

    $acs = $this->post($this->provider->samlAcsUrl(), ['SAMLResponse' => $response, 'RelayState' => $request['relay_state']])->assertStatus(303);
    $this->get((string) $acs->headers->get('Location'))->assertRedirect('/app/login?sso_error=sso_failed');

    coreFrontExpectNoProviderFault($this);
    expect(Activity::query()->where('event', 'auth.sso_failed')->sole()->properties->all())
        ->toMatchArray(['misconfiguration' => null, 'cause' => 'saml_status']);
});
