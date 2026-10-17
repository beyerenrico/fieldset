<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Contracts\ProviderHealthCheck;
use App\Domain\Identity\Sso\Contracts\SsoMisconfigurationReporter;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Providers\SsrfGuardedHttpClient;
use App\Domain\Identity\Tenancy\TenantContext;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Tests\Feature\Workflows\FakeHostResolver;
use Tests\Support\Sso\FakeOidcIdp;
use Tests\Support\Sso\FakeSamlIdp;

beforeEach(function (): void {
    $this->reports = [];
    $reports = &$this->reports;
    app()->instance(SsoMisconfigurationReporter::class, new class($reports) implements SsoMisconfigurationReporter
    {
        /** @param  list<array{0: string, 1: string}>  $reports */
        public function __construct(private array &$reports) {}

        public function report(IdentityProvider $p, string $reason, string $detail): void
        {
            $this->reports[] = [$p->uuid, $reason];
        }
    });
    $this->idp = FakeOidcIdp::bind();
    $this->org = makeOrganization(name: 'Acme GmbH');
    $this->provider = $this->idp->provider($this->org);
});

it('passes for a reachable discovery document and JWKS with keys', function (): void {
    expect(app(ProviderHealthCheck::class)->check($this->provider))->ok->toBeTrue()->reason->toBeNull();
});

it('names the failing part: JWKS, discovery, issuer, SSRF', function (Closure $arrange, string $reason): void {
    $arrange->call($this);

    $health = app(ProviderHealthCheck::class)->check($this->provider->fresh());

    expect($health->ok)->toBeFalse()->and($health->reason)->toBe($reason)->and($health->detail)->not->toBeEmpty();
})->with([
    'jwks 500' => [function (): void {
        $this->idp->failWith('/jwks', 500);
    }, 'jwks'],
    'discovery 503' => [function (): void {
        $this->idp->failWith('/.well-known/openid-configuration', 503);
    }, 'discovery'],
    'issuer mismatch' => [function (): void {
        app(TenantContext::class)->run($this->org, fn () => $this->provider->forceFill(['config' => [...$this->provider->config, 'issuer' => 'https://idp.example.test/realms/other', 'discovery_url' => $this->idp->issuer]])->save());
    }, 'issuer'],
    'private host' => [function (): void {
        FakeOidcIdp::bind('https://idp.example.test/realms/acme', ['idp.example.test' => ['127.0.0.1']]);
    }, 'blocked_host'],
]);

it('checks SAML metadata from XML and from a URL', function (): void {
    $saml = new FakeSamlIdp;
    $fromXml = $saml->provider(makeOrganization(name: 'Xml GmbH'));
    expect(app(ProviderHealthCheck::class)->check($fromXml)->ok)->toBeTrue();

    app()->instance(SsrfGuardedHttpClient::class, new SsrfGuardedHttpClient(FakeHostResolver::bind())
        ->withHandler(fn () => Create::promiseFor(new Psr7Response(500, [], 'down'))));
    $fromUrl = $saml->provider(makeOrganization(name: 'Url GmbH'), [], ['metadata_xml' => null, 'metadata_url' => 'https://idp.example.test/saml/metadata']);

    expect(app(ProviderHealthCheck::class)->check($fromUrl))->ok->toBeFalse()->reason->toBe('metadata');
});

it('sso:check-providers sets error with a report, then recovers, and skips drafts', function (): void {
    $draft = $this->idp->provider(makeOrganization(name: 'Draft GmbH'), ['status' => IdentityProviderStatus::Draft]);
    $this->idp->failWith('/jwks', 500);

    $this->artisan('sso:check-providers')->assertSuccessful();

    $provider = $this->provider->fresh();
    expect($provider->status)->toBe(IdentityProviderStatus::Error)
        ->and($provider->last_error)->toStartWith('jwks: ')
        ->and($this->reports)->toBe([[$provider->uuid, 'jwks']])
        ->and($draft->fresh()->status)->toBe(IdentityProviderStatus::Draft)
        ->and(Activity::query()->where('event', 'sso.provider_status_changed')->sole()->organization_id)->toBe($this->org->id);

    FakeOidcIdp::bind();
    $this->artisan('sso:check-providers')->assertSuccessful();

    expect($provider->fresh())->status->toBe(IdentityProviderStatus::Active)->last_error->toBeNull()->last_success_at->not->toBeNull()
        ->and($this->reports)->toHaveCount(1);
});

it('reports when no provider takes logins', function (): void {
    app(TenantContext::class)->run($this->org, fn () => $this->provider->forceFill(['status' => IdentityProviderStatus::Disabled])->save());

    $this->artisan('sso:check-providers')->expectsOutputToContain(__('sso.command.none'))->assertSuccessful();
});
