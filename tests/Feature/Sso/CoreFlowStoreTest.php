<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use App\Domain\Identity\Sso\Login\SsoFlowStore;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Sso\FakeOidcIdp;
use Tests\Support\Sso\FakeSamlIdp;

/*
 * Gate review M7, findings 8 and 9: parallel admin and Field App logins in one session keep their own flows
 * (including the OIDC package's state, nonce and PKCE verifier), and the one-time SAML relay and ticket are
 * taken atomically.
 */
beforeEach(function (): void {
    $this->idp = FakeOidcIdp::bind();
    $this->org = makeOrganization(name: 'Acme GmbH');
    FakeOidcIdp::domain($this->org, 'acme.example');
    $this->provider = $this->idp->provider($this->org);
});

function coreFlowSamlProvider(FakeSamlIdp $saml): IdentityProvider
{
    $organization = makeOrganization(name: 'Saml GmbH');
    FakeOidcIdp::domain($organization, 'saml.example');

    return $saml->provider($organization);
}

function coreFlowLocation(IdentityProvider $provider, SsoClient $client): string
{
    return (string) test()->get(FakeOidcIdp::startUrl($provider, $client))->assertRedirect()->headers->get('Location');
}

it('keeps parallel OIDC logins of one session apart, in either order', function (): void {
    User::factory()->memberOf($this->org, SystemRole::Designer)->create(['email' => 'des@acme.example']);
    $admin = coreFlowLocation($this->provider, SsoClient::Admin);
    $app = coreFlowLocation($this->provider, SsoClient::App);

    $this->get($this->idp->authorize($admin, ['sub' => 'kc-1', 'email' => 'des@acme.example']))->assertRedirect(url('/admin'));
    auth()->logout();
    $this->get($this->idp->authorize($app, ['sub' => 'kc-1', 'email' => 'des@acme.example']))->assertRedirect('/app/login?sso=ok');
});

it('keeps parallel SAML logins of one session apart', function (): void {
    $saml = new FakeSamlIdp;
    $provider = coreFlowSamlProvider($saml);
    $first = $saml->authnRequest(coreFlowLocation($provider, SsoClient::App));
    $saml->authnRequest(coreFlowLocation($provider, SsoClient::Admin));

    $acs = $this->post($provider->samlAcsUrl(), [
        'SAMLResponse' => $saml->response($first, $provider->samlEntityId(), ['email' => 'jane@saml.example']), 'RelayState' => $first['relay_state'],
    ])->assertStatus(303);

    $this->get((string) $acs->headers->get('Location'))->assertRedirect('/app/login?sso=ok');
});

it('takes the SAML relay and ticket atomically: while another request holds them they count as taken', function (): void {
    $saml = new FakeSamlIdp;
    $provider = coreFlowSamlProvider($saml);
    $request = $saml->authnRequest(coreFlowLocation($provider, SsoClient::App));
    $relayLock = Cache::lock('sso:saml:relay:'.hash('sha256', $request['relay_state']).':pull', 10);
    $relayLock->get();

    $this->post($provider->samlAcsUrl(), [
        'SAMLResponse' => $saml->response($request, $provider->samlEntityId(), ['email' => 'jane@acme.example']), 'RelayState' => $request['relay_state'],
    ])->assertStatus(303)->assertRedirect(route('sso.saml.complete'));
    $relayLock->release();

    $store = app(SsoFlowStore::class);
    $ticket = $store->issueTicket('flow-id', $provider->uuid, SsoRefusal::SsoFailed);
    $ticketLock = Cache::lock('sso:saml:ticket:'.hash('sha256', $ticket).':pull', 10);
    $ticketLock->get();
    expect($store->redeemTicket($ticket))->toBeNull();
    $ticketLock->release();
    expect($store->redeemTicket($ticket))->toMatchArray(['flow' => 'flow-id', 'result' => SsoRefusal::SsoFailed])
        ->and($store->redeemTicket($ticket))->toBeNull();
});
