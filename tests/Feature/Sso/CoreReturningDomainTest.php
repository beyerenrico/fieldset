<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Tenancy\TenantContext;
use Tests\Support\Sso\FakeOidcIdp;

/*
 * Gate review M7, finding 4: a returning login (found by provider and subject) re-checks the email domain —
 * once another organization holds it verified, the old organization's IdP may no longer sign that person in.
 */
beforeEach(function (): void {
    $this->idp = FakeOidcIdp::bind();
    $this->org = makeOrganization(name: 'Acme GmbH');
    $this->domain = FakeOidcIdp::domain($this->org, 'acme.example');
    $this->provider = $this->idp->provider($this->org);
    $this->idp->login($this->provider, ['sub' => 'kc-1', 'email' => 'jane@acme.example'])->assertRedirect('/app/login?sso=ok');
    auth()->logout();
});

function coreReturningUnverify(SsoDomain $domain, int $organizationId): void
{
    app(TenantContext::class)->run($organizationId, fn () => $domain->forceFill(['verified_at' => null])->save());
}

it('refuses a returning identity whose domain another organization now holds verified, and audits it', function (): void {
    coreReturningUnverify($this->domain, $this->org->id);
    FakeOidcIdp::domain(makeOrganization(name: 'New Owner GmbH'), 'acme.example');

    $this->idp->login($this->provider, ['sub' => 'kc-1', 'email' => 'jane@acme.example'])->assertRedirect('/app/login?sso_error=domain_not_verified');

    $this->assertGuest();
    $refusal = Activity::query()->where('event', 'auth.sso_refused')->sole();
    expect($refusal->properties->all())->toMatchArray(['reason' => 'domain_not_verified', 'detail' => 'domain_moved', 'subject' => 'kc-1'])
        ->and($refusal->organization_id)->toBe($this->org->id);
});

it('keeps returning identities whose domain nobody else verified', function (): void {
    coreReturningUnverify($this->domain, $this->org->id);

    $this->idp->login($this->provider, ['sub' => 'kc-1', 'email' => 'jane@acme.example'])->assertRedirect('/app/login?sso=ok');
});
