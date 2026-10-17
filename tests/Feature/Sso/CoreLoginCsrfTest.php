<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enums\SsoClient;
use Tests\Support\Sso\FakeOidcIdp;

/*
 * Gate review M7, finding 11: a login starts only from a CSRF-protected step of the same session (discovery,
 * the password gate, an invitation) — never from a bare cross-site GET to sso.redirect. Test mode keeps
 * working for signed-in members with members.manage.
 */
beforeEach(function (): void {
    $this->idp = FakeOidcIdp::bind();
    $this->org = makeOrganization(name: 'Acme GmbH');
    FakeOidcIdp::domain($this->org, 'acme.example');
    $this->provider = $this->idp->provider($this->org);
});

it('starts no login from a link without a start token of this session', function (): void {
    $this->get($this->provider->redirectUrl(SsoClient::App, 'jane@acme.example'))->assertRedirect('/app/login?sso_error=sso_failed');
    $this->get($this->provider->redirectUrl(SsoClient::Admin))->assertRedirect(url('/admin/login?sso_error=sso_failed'));
    $this->get($this->provider->redirectUrl(SsoClient::App).'&start=guessed0000000000000000000000000000000000')->assertRedirect('/app/login?sso_error=sso_failed');

    expect($this->idp->requests)->toBe([])
        ->and(Activity::query()->where('event', 'auth.sso_failed')->pluck('properties')->pluck('detail')->unique()->values()->all())->toBe(['start_missing']);
});

it('accepts a token only in its session and for its provider and client', function (): void {
    $app = FakeOidcIdp::startUrl($this->provider, SsoClient::App);
    $token = substr($app, -40);
    $other = $this->idp->provider(makeOrganization(name: 'Other GmbH'));

    $this->get($this->provider->redirectUrl(SsoClient::Admin).'&start='.$token)->assertRedirect(url('/admin/login?sso_error=sso_failed'));
    $this->get($other->redirectUrl(SsoClient::App).'&start='.$token)->assertRedirect('/app/login?sso_error=sso_failed');
    $this->flushSession();
    $this->get($app)->assertRedirect('/app/login?sso_error=sso_failed');

    expect($this->idp->requests)->toBe([]);
});

it('starts the login from the URL discovery answered in this session', function (): void {
    $url = (string) $this->postJson('/auth/sso/discover', ['email' => 'jane@acme.example', 'client' => 'admin'])->assertOk()->json('redirect_url');

    expect($this->get($url)->assertRedirect()->headers->get('Location'))->toStartWith($this->idp->issuer.'/protocol/openid-connect/auth?');
});

it('lets test mode start without a token for members.manage only', function (): void {
    $this->actingAs(User::factory()->memberOf($this->org, SystemRole::Admin)->create());

    expect($this->get($this->provider->redirectUrl(SsoClient::Admin, null, true))->assertRedirect()->headers->get('Location'))
        ->toStartWith($this->idp->issuer);
});
