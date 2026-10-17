<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\SsoMisconfigurationReporter;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Providers\SsrfGuardedHttpClient;
use App\Domain\Identity\Tenancy\TenantContext;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Workflows\FakeHostResolver;
use Tests\Support\Sso\FakeOidcIdp;
use Tests\Support\Sso\FakeSamlIdp;

beforeEach(function (): void {
    $this->reports = [];
    $reports = &$this->reports;
    app()->instance(SsoMisconfigurationReporter::class, new class($reports) implements SsoMisconfigurationReporter
    {
        /** @param  list<string>  $reports */
        public function __construct(private array &$reports) {}

        public function report(IdentityProvider $p, string $reason, string $detail): void
        {
            $this->reports[] = $reason;
        }
    });
    $this->org = makeOrganization(name: 'Acme GmbH');
    FakeOidcIdp::domain($this->org, 'acme.example');
    $this->idp = new FakeSamlIdp;
    $this->provider = $this->idp->provider($this->org);
});

/** @return array{id: string, relay_state: string, acs: string, force_authn: bool} */
function samlStart(FakeSamlIdp $idp, IdentityProvider $provider, SsoClient $client = SsoClient::App): array
{
    $location = (string) test()->get(FakeOidcIdp::startUrl($provider, $client))->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith(FakeSamlIdp::SSO_URL.'?SAMLRequest=');

    return $idp->authnRequest($location);
}

function samlPost(IdentityProvider $provider, string $response, string $relayState): TestResponse
{
    return test()->post($provider->samlAcsUrl(), ['SAMLResponse' => $response, 'RelayState' => $relayState]);
}

/** ACS, then the 303 to the completion route. */
function samlFinish(IdentityProvider $provider, string $response, string $relayState): TestResponse
{
    $acs = samlPost($provider, $response, $relayState)->assertStatus(303);
    expect($acs->headers->get('Location'))->toStartWith(route('sso.saml.complete'));

    return test()->get((string) $acs->headers->get('Location'));
}

it('signs in through a signed assertion with JIT and mapped groups', function (): void {
    $designer = Role::query()->where('organization_id', $this->org->id)->where('name', 'designer')->firstOrFail();
    app(TenantContext::class)->run($this->org, fn () => IdentityProviderMapping::factory()->toRole($designer)
        ->create(['identity_provider_id' => $this->provider->id, 'claim' => 'groups', 'value' => 'designers']));
    $request = samlStart($this->idp, $this->provider);

    expect($request['acs'])->toBe($this->provider->samlAcsUrl())->and($request['relay_state'])->toHaveLength(40);
    $response = $this->idp->response($request, $this->provider->samlEntityId(), ['email' => 'Jane@acme.example', 'groups' => ['designers', 'staff']]);
    samlFinish($this->provider, $response, $request['relay_state'])->assertRedirect('/app/login?sso=ok');

    $user = User::query()->where('email', 'jane@acme.example')->sole();
    expect(auth()->id())->toBe($user->id)
        ->and(app(TenantContext::class)->run($this->org, fn (): array => $user->roles()->pluck('name')->sort()->values()->all()))->toBe(['designer', 'field_worker']);
    $login = Activity::query()->where('event', 'auth.login')->sole();
    expect($login->properties->all())->toMatchArray(['method' => 'sso', 'protocol' => 'saml', 'provider' => $this->provider->uuid, 'email_verified_by' => 'saml'])
        ->and($login->properties['amr'])->toBe(['urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport']);
});

it('builds the name from Keycloak-style firstName and lastName attributes', function (): void {
    $request = samlStart($this->idp, $this->provider);
    $response = $this->idp->response($request, $this->provider->samlEntityId(), ['email' => 'ann@acme.example', 'firstName' => 'Ann', 'lastName' => 'Lee']);

    samlFinish($this->provider, $response, $request['relay_state'])->assertRedirect('/app/login?sso=ok');

    expect(User::query()->where('email', 'ann@acme.example')->sole()->name)->toBe('Ann Lee');
});

it('runs the ACS without a session: no cookie, and the result only completes in the starting session', function (): void {
    $request = samlStart($this->idp, $this->provider);
    $acs = samlPost($this->provider, $this->idp->response($request, $this->provider->samlEntityId(), ['email' => 'jane@acme.example']), $request['relay_state']);

    expect(collect($acs->headers->getCookies())->map->getName()->all())->not->toContain(config('session.cookie'));
    // Another session (here: the starting one is gone) cannot redeem the ticket.
    $this->flushSession();
    $this->get((string) $acs->headers->get('Location'))->assertRedirect('/app/login?sso_error=sso_failed');
    $this->assertGuest();
    expect(Activity::query()->where('event', 'auth.sso_failed')->sole()->properties['detail'])->toBe('saml_ticket_binding');
});

it('serves the SP metadata with the entity id and the HTTP-POST ACS', function (): void {
    $xml = $this->get($this->provider->samlEntityId())->assertOk()->assertHeader('content-type', 'application/samlmetadata+xml')->getContent();

    expect($xml)->toContain('entityID="'.$this->provider->samlEntityId().'"')
        ->toContain('Location="'.$this->provider->samlAcsUrl().'"')
        ->toContain('WantAssertionsSigned="true"');
    $this->get(new FakeOidcIdp('https://x.example')->provider(makeOrganization(name: 'Oidc'))->samlEntityId())->assertNotFound();
});

it('refuses unsigned assertions and foreign signatures without counting a posted response as a provider fault', function (?string $sign, string $cause): void {
    $request = samlStart($this->idp, $this->provider);
    $response = $this->idp->response($request, $this->provider->samlEntityId(), ['email' => 'jane@acme.example'], ['sign' => $sign]);

    samlFinish($this->provider, $response, $request['relay_state'])->assertRedirect('/app/login?sso_error=sso_failed');

    $this->assertGuest();
    $failure = Activity::query()->where('event', 'auth.sso_failed')->sole();
    expect($this->reports)->toBe([])
        ->and($this->provider->fresh()->status)->toBe(IdentityProviderStatus::Active)
        ->and($failure->properties['misconfiguration'])->toBeNull()
        ->and($failure->properties['cause'])->toBe($cause);
})->with([
    'unsigned' => [null, 'saml_signature'],
    'foreign key' => ['foreign', 'saml_signature'],
]);

it('refuses an XML signature wrapping attack', function (): void {
    $request = samlStart($this->idp, $this->provider);
    $genuine = $this->idp->response($request, $this->provider->samlEntityId(), ['email' => 'jane@acme.example']);

    samlFinish($this->provider, FakeSamlIdp::wrapSignature($genuine, 'attacker@acme.example'), $request['relay_state'])
        ->assertRedirect('/app/login?sso_error=sso_failed');

    $this->assertGuest();
    expect(User::query()->whereIn('email', ['attacker@acme.example', 'jane@acme.example'])->exists())->toBeFalse();
});

it('refuses a DTD with an external entity (XXE) without reading the file', function (): void {
    $secret = tempnam(sys_get_temp_dir(), 'xxe');
    file_put_contents($secret, 'TOP-SECRET-XXE-MARKER');
    $request = samlStart($this->idp, $this->provider);
    $response = $this->idp->response($request, $this->provider->samlEntityId(), ['email' => 'jane@acme.example']);

    samlFinish($this->provider, FakeSamlIdp::withExternalEntity($response, (string) $secret), $request['relay_state'])
        ->assertRedirect('/app/login?sso_error=sso_failed');

    unlink((string) $secret);
    $this->assertGuest();
    expect(Activity::query()->get()->toJson())->not->toContain('TOP-SECRET-XXE-MARKER')
        ->and(Activity::query()->where('event', 'auth.sso_failed')->sole()->properties['detail'])->toContain('document type declaration')
        ->and($this->reports)->toBe([]);
});

it('refuses replays: a used RelayState and a repeated assertion id', function (): void {
    $request = samlStart($this->idp, $this->provider);
    $response = $this->idp->response($request, $this->provider->samlEntityId(), ['email' => 'jane@acme.example'], ['assertion_id' => '_fixed-assertion-id-0123456789']);
    samlFinish($this->provider, $response, $request['relay_state'])->assertRedirect('/app/login?sso=ok');
    auth()->logout();

    samlPost($this->provider, $response, $request['relay_state'])->assertStatus(303)->assertRedirect(route('sso.saml.complete'));

    $again = samlStart($this->idp, $this->provider);
    $replayed = $this->idp->response($again, $this->provider->samlEntityId(), ['email' => 'jane@acme.example'], ['assertion_id' => '_fixed-assertion-id-0123456789']);
    samlFinish($this->provider, $replayed, $again['relay_state'])->assertRedirect('/app/login?sso_error=sso_failed');

    $this->assertGuest();
    expect(Activity::query()->where('event', 'auth.sso_failed')->pluck('properties')->pluck('detail')->implode(' '))
        ->toContain('saml_relay_state')->toContain('repeated an assertion id');
});

it('refuses wrong audience, IdP-initiated or foreign InResponseTo, wrong recipient and expired assertions', function (array $options): void {
    $request = samlStart($this->idp, $this->provider);
    $response = $this->idp->response($request, $this->provider->samlEntityId(), ['email' => 'jane@acme.example'], $options);

    samlFinish($this->provider, $response, $request['relay_state'])->assertRedirect('/app/login?sso_error=sso_failed');

    $this->assertGuest();
    expect($this->reports)->toBe([]);
})->with([
    'audience' => [['audience' => 'https://other-sp.example']],
    'in response to' => [['in_response_to' => '_another-request-0123456789']],
    'recipient' => [['recipient' => 'https://evil.example/acs']],
    'expired' => [['not_on_or_after' => '-10 minutes']],
]);

it('audits an assertion from another issuer without reporting it (anyone can post one)', function (): void {
    $request = samlStart($this->idp, $this->provider);
    $response = $this->idp->response($request, $this->provider->samlEntityId(), ['email' => 'jane@acme.example'], ['issuer' => 'https://evil-idp.example']);

    samlFinish($this->provider, $response, $request['relay_state'])->assertRedirect('/app/login?sso_error=sso_failed');

    expect($this->reports)->toBe([])
        ->and($this->provider->fresh()->status)->toBe(IdentityProviderStatus::Active)
        ->and(Activity::query()->where('event', 'auth.sso_failed')->sole()->properties['cause'])->toBe('saml_issuer');
});

it('sets ForceAuthn with force_reauth and loads IdP metadata from a URL through the SSRF guard', function (): void {
    $xml = $this->idp->metadataXml();
    $fetched = [];
    $client = new SsrfGuardedHttpClient(FakeHostResolver::bind(['intranet.example.test' => ['192.168.1.9']]))
        ->withHandler(function ($request) use ($xml, &$fetched): PromiseInterface {
            $fetched[] = (string) $request->getUri();

            return Create::promiseFor(new Psr7Response(200, ['Content-Type' => 'application/samlmetadata+xml'], $xml));
        });
    app()->instance(SsrfGuardedHttpClient::class, $client);
    $provider = $this->idp->provider(makeOrganization(name: 'Url GmbH'), ['force_reauth' => true], ['metadata_xml' => null, 'metadata_url' => 'https://idp.example.test/saml/metadata']);

    expect(samlStart($this->idp, $provider)['force_authn'])->toBeTrue()
        ->and($fetched)->toBe(['https://idp.example.test/saml/metadata']);

    $blocked = $this->idp->provider(makeOrganization(name: 'Intranet'), [], ['metadata_xml' => null, 'metadata_url' => 'https://intranet.example.test/metadata']);
    $this->get(FakeOidcIdp::startUrl($blocked))->assertRedirect('/app/login?sso_error=provider_unavailable');
    expect($this->reports)->toBe(['blocked_host']);
});
