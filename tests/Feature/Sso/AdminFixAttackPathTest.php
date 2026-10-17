<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Admin\SaveIdentityProvider;
use App\Domain\Identity\Sso\Admin\SetSsoEnforcement;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Login\SsoLoginResponder;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\FakeNotifier;
use App\Domain\Notifications\Notifier;
use App\Filament\Admin\Pages\Sso\SsoSettings;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Sso\FakeOidcIdp;

/*
 * Gate review M7, FS-admin item 1 (HIGH): an Admin (members.manage) must not be able to repoint the IdP to
 * their own server, activate it with a test login and then sign in as an Owner of a verified domain.
 */

beforeEach(function (): void {
    app()->instance(Notifier::class, new FakeNotifier);
    $this->idp = FakeOidcIdp::bind();
    $this->org = makeOrganization(name: 'Acme GmbH');
    FakeOidcIdp::domain($this->org, 'acme.example');
    $this->owner = ownerOf($this->org);
    $this->owner->forceFill(['email' => 'boss@acme.example', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
    $this->provider = $this->idp->provider($this->org, ['status' => IdentityProviderStatus::Draft]); // set up by the Owner
    app(TenantContext::class)->run($this->org, fn () => ExternalIdentity::factory()->create([
        'identity_provider_id' => $this->provider->id, 'user_id' => $this->owner->id, 'subject' => 'boss-subject',
    ]));
    $this->admin = memberOf($this->org, SystemRole::Admin);
    $this->admin->forceFill(['email' => 'admin@acme.example'])->save();
});

it('keeps the connection out of an Admin\'s reach on the page and in the action', function (): void {
    actingInAdmin($this->admin, $this->org);

    Livewire::test(SsoSettings::class)
        ->assertFormFieldDisabled('issuer', 'providerForm')
        ->assertFormFieldDisabled('client_id', 'providerForm')
        ->assertFormFieldDisabled('trust_unverified_email', 'providerForm')
        ->assertSee(__('sso_admin.provider.owner_only_note'))
        ->fillForm(['issuer' => 'https://attacker.example/realms/evil', 'client_id' => 'evil', 'trust_unverified_email' => true, 'name' => 'Still Acme'], 'providerForm')
        ->call('saveProvider')
        ->assertHasNoErrors();

    $provider = $this->provider->fresh();
    expect($provider->config)->toMatchArray(['issuer' => $this->idp->issuer, 'client_id' => FakeOidcIdp::CLIENT_ID, 'trust_unverified_email' => false])
        ->and($provider->name)->toBe('Still Acme')
        ->and(ExternalIdentity::query()->withoutTenant()->count())->toBe(1);

    $attempt = fn () => app(SaveIdentityProvider::class)->handle($this->org, IdentityProviderType::Oidc, 'Acme', [
        'flavor' => 'generic', 'issuer' => 'https://attacker.example/realms/evil', 'client_id' => FakeOidcIdp::CLIENT_ID,
    ], null, $this->admin);
    expect($attempt)->toThrow(ValidationException::class, __('sso_admin.provider.errors.owner_only'))
        ->and($this->provider->fresh()->config['issuer'])->toBe($this->idp->issuer);
});

it('lets an Admin\'s test login of a draft activate it and nothing more', function (): void {
    actingInAdmin($this->admin, $this->org);
    $this->withHeader('Referer', SsoSettings::getUrl());

    $location = (string) $this->get($this->provider->redirectUrl(SsoClient::Admin, null, true))->assertRedirect()->headers->get('Location');
    $this->get($this->idp->authorize($location, ['sub' => 'boss-subject', 'email' => 'boss@acme.example']))
        ->assertRedirect(SsoSettings::getUrl())->assertSessionHas(SsoLoginResponder::TEST_RESULT_FLASH);

    expect(auth()->id())->toBe($this->admin->id) // still the Admin, never the Owner whose subject came back
        ->and($this->provider->fresh()->status)->toBe(IdentityProviderStatus::Active)
        ->and(ExternalIdentity::query()->withoutTenant()->pluck('user_id')->all())->toBe([$this->owner->id])
        ->and(app(SetSsoEnforcement::class)->conditions($this->provider->fresh(), $this->owner, $this->admin)['no_lockout'])->toBeFalse()
        ->and(User::query()->where('email', 'boss@acme.example')->sole()->id)->toBe($this->owner->id);
});

it('keeps enforcement on against an Admin', function (): void {
    app(TenantContext::class)->run($this->org, function (): void {
        $this->provider->forceFill(['status' => IdentityProviderStatus::Active])->save();
        app(SetSsoEnforcement::class)->handle($this->provider, true, $this->owner, $this->owner);
        ExternalIdentity::factory()->create(['identity_provider_id' => $this->provider->id, 'user_id' => $this->admin->id]);
    });
    actingInAdmin($this->admin, $this->org);

    Livewire::test(SsoSettings::class)
        ->assertFormFieldDisabled('enforce_sso', 'enforcementForm')
        ->assertSee(__('sso_admin.enforcement.owner_only_note'))
        ->fillForm(['enforce_sso' => false], 'enforcementForm')
        ->call('saveEnforcement')
        ->assertHasNoErrors();
    expect($this->provider->fresh()->enforce_sso)->toBeTrue();

    expect(fn () => app(SetSsoEnforcement::class)->handle($this->provider, false, null, $this->admin))
        ->toThrow(ValidationException::class, __('sso_admin.enforcement.errors.owner_only_disable'))
        ->and($this->provider->fresh()->enforce_sso)->toBeTrue();
});

it('does not offer an Admin the provider setup', function (): void {
    app(TenantContext::class)->run($this->org, fn () => IdentityProvider::query()->delete());
    actingInAdmin($this->admin, $this->org);

    Livewire::test(SsoSettings::class)
        ->assertSee(__('sso_admin.provider.owner_only_setup'))
        ->assertDontSee(__('sso_admin.provider.save'))
        ->assertDontSee(__('sso_admin.status.addresses_title'))
        ->call('saveProvider')
        ->assertForbidden();
});
