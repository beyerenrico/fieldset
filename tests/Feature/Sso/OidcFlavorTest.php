<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Models\User;
use Tests\Support\Sso\FakeOidcIdp;

const OIDC_FLAVOR_ENTRA_TENANT = '6f1d2c3b-4a59-4e8f-9b0a-1c2d3e4f5a6b';

beforeEach(function (): void {
    $this->org = makeOrganization(name: 'Acme GmbH');
    FakeOidcIdp::domain($this->org, 'acme.example');
});

describe('Google Workspace', function (): void {
    beforeEach(function (): void {
        $this->idp = FakeOidcIdp::bind('https://accounts.google.com');
        $this->provider = $this->idp->provider($this->org, [], ['flavor' => 'google', 'issuer' => null]);
    });

    it('accepts a verified email whose hd the organization holds verified, with either documented issuer', function (string $iss): void {
        $this->idp->login($this->provider, ['iss' => $iss, 'sub' => 'g-1', 'email' => 'jane@acme.example', 'hd' => 'acme.example'])
            ->assertRedirect('/app/login?sso=ok');

        expect(User::query()->where('email', 'jane@acme.example')->exists())->toBeTrue();
    })->with(['https://accounts.google.com', 'accounts.google.com']);

    it('refuses a missing or unverified hd and an unverified email', function (array $claims, string $code): void {
        FakeOidcIdp::domain($this->org, 'claimed.example', verified: false);

        $this->idp->login($this->provider, ['sub' => 'g-2', 'email' => 'jane@acme.example', ...$claims])
            ->assertRedirect('/app/login?sso_error='.$code);

        $this->assertGuest();
    })->with([
        'no hd (consumer account)' => [['hd' => null], 'domain_not_verified'],
        'hd not verified' => [['hd' => 'claimed.example'], 'domain_not_verified'],
        'hd of another organization' => [['hd' => 'gmail.example'], 'domain_not_verified'],
        'email not verified' => [['hd' => 'acme.example', 'email_verified' => false], 'email_unverified'],
    ]);
});

describe('Microsoft Entra ID', function (): void {
    beforeEach(function (): void {
        $this->idp = FakeOidcIdp::bind('https://login.microsoftonline.com/'.OIDC_FLAVOR_ENTRA_TENANT.'/v2.0');
        $this->provider = $this->idp->provider($this->org, [], ['flavor' => 'entra', 'tenant' => strtoupper(OIDC_FLAVOR_ENTRA_TENANT), 'issuer' => null]);
    });

    it('signs in with the UPN of the configured tenant and ignores the spoofable email claim', function (): void {
        $this->idp->login($this->provider, [
            'sub' => 'e-1', 'tid' => OIDC_FLAVOR_ENTRA_TENANT, 'preferred_username' => 'Jane@acme.example', 'email' => 'ceo@acme.example', 'email_verified' => null,
        ])->assertRedirect('/app/login?sso=ok');

        expect(User::query()->where('email', 'jane@acme.example')->exists())->toBeTrue()
            ->and(User::query()->where('email', 'ceo@acme.example')->exists())->toBeFalse()
            ->and(Activity::query()->where('event', 'auth.login')->sole()->properties['email_verified_by'])->toBe('upn');
    });

    it('refuses a token of another tenant', function (): void {
        $this->idp->login($this->provider, ['sub' => 'e-2', 'tid' => '00000000-0000-4000-8000-000000000000', 'preferred_username' => 'jane@acme.example'])
            ->assertRedirect('/app/login?sso_error=sso_failed');

        expect(Activity::query()->where('event', 'auth.sso_failed')->sole()->properties['misconfiguration'])->toBe('tenant');
    });

    it('refuses a login without an email-shaped UPN', function (): void {
        $this->idp->login($this->provider, ['sub' => 'e-3', 'tid' => OIDC_FLAVOR_ENTRA_TENANT, 'preferred_username' => '+49 151 000000', 'email' => 'jane@acme.example'])
            ->assertRedirect('/app/login?sso_error=email_unverified');
    });

    it('needs the tenant GUID', function (): void {
        $provider = $this->idp->provider(makeOrganization(name: 'Contoso'), [], ['flavor' => 'entra', 'tenant' => 'contoso.onmicrosoft.com']);

        $this->get(FakeOidcIdp::startUrl($provider))->assertRedirect('/app/login?sso_error=provider_unavailable');

        expect(Activity::query()->where('event', 'auth.sso_failed')->sole()->properties['misconfiguration'])->toBe('config')
            ->and($this->idp->requests)->toBe([]);
    });
});

describe('generic OIDC', function (): void {
    it('accepts an unverified email only with the audited opt-in', function (): void {
        $idp = FakeOidcIdp::bind();
        $provider = $idp->provider($this->org);

        $idp->login($provider, ['sub' => 'k-1', 'email' => 'jane@acme.example', 'email_verified' => false])
            ->assertRedirect('/app/login?sso_error=email_unverified');

        $trusting = $idp->provider(makeOrganization(name: 'Trusting'), [], ['trust_unverified_email' => true]);
        FakeOidcIdp::domain($trusting->organization, 'trusting.example');
        $idp->login($trusting, ['sub' => 'k-2', 'email' => 'jane@trusting.example', 'email_verified' => false])
            ->assertRedirect('/app/login?sso=ok');

        expect(Activity::query()->where('event', 'auth.login')->sole()->properties['email_verified_by'])->toBe('trusted');
    });
});
