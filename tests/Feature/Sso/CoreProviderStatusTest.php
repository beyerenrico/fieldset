<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Contracts\ProviderHealthCheck;
use App\Domain\Identity\Sso\Data\ProviderHealth;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Login\ProviderStatusRecorder;
use Tests\Support\Sso\FakeOidcIdp;

/*
 * Gate review M7, finding 6: the status is written from a model read before a slow IdP round trip; a change
 * an admin made meanwhile (draft after a connection change, disabled) must survive.
 */
beforeEach(function (): void {
    $this->reports = FakeOidcIdp::spyReporter();
    $this->idp = FakeOidcIdp::bind();
    $this->org = makeOrganization(name: 'Acme GmbH');
    $this->provider = $this->idp->provider($this->org);
});

function coreStatusSetInDatabase(IdentityProvider $provider, IdentityProviderStatus $status): void
{
    IdentityProvider::query()->withoutTenant()->whereKey($provider->id)->update(['status' => $status->value]);
}

it('never overwrites draft or disabled with error or active from a stale model', function (): void {
    $recorder = app(ProviderStatusRecorder::class);
    $stale = $this->provider; // loaded as active

    coreStatusSetInDatabase($stale, IdentityProviderStatus::Draft);
    expect($recorder->failed($stale, 'jwks', 'down'))->toBe(IdentityProviderStatus::Draft)
        ->and($this->provider->fresh())->status->toBe(IdentityProviderStatus::Draft)->last_error->toBe('jwks: down');

    coreStatusSetInDatabase($stale, IdentityProviderStatus::Disabled);
    $stale->forceFill(['status' => IdentityProviderStatus::Error]);
    expect($recorder->succeeded($stale))->toBe(IdentityProviderStatus::Disabled)
        ->and($this->provider->fresh()->status)->toBe(IdentityProviderStatus::Disabled)
        ->and(Activity::query()->where('event', 'sso.provider_status_changed')->count())->toBe(0);
});

it('applies the transition to the current status (error after an admin re-activated)', function (): void {
    $stale = $this->provider->replicate()->forceFill(['id' => $this->provider->id, 'status' => IdentityProviderStatus::Error]);

    expect(app(ProviderStatusRecorder::class)->failed($stale, 'jwks', 'down'))->toBe(IdentityProviderStatus::Error)
        ->and(Activity::query()->where('event', 'sso.provider_status_changed')->sole()->properties->all())
        ->toMatchArray(['from' => 'active', 'to' => 'error']);
});

it('sso:check-providers neither resurrects nor reports a provider drafted during the check', function (): void {
    app()->instance(ProviderHealthCheck::class, new readonly class implements ProviderHealthCheck
    {
        public function check(IdentityProvider $p): ProviderHealth
        {
            coreStatusSetInDatabase($p, IdentityProviderStatus::Draft); // an admin changes the connection meanwhile

            return new ProviderHealth(false, 'jwks', 'down');
        }
    });

    $this->artisan('sso:check-providers')->assertSuccessful();

    expect($this->provider->fresh()->status)->toBe(IdentityProviderStatus::Draft)
        ->and($this->reports->getArrayCopy())->toBe([]);
});
