<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enums\DomainVerification;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Filament\Admin\Pages\Sso\SsoSettings;
use Livewire\Livewire;
use Tests\Support\Sso\FakeTxtRecordResolver;

function ssoAdminPageOwnerWithMfa(User $user): User
{
    $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();

    return $user->refresh();
}

beforeEach(function (): void {
    $this->dns = FakeTxtRecordResolver::bind();
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
});

it('is open to Owners and Admins only', function (SystemRole $role, int $status): void {
    $user = $role === SystemRole::Owner ? $this->owner : memberOf($this->org, $role);
    actingInAdmin($user, $this->org);

    $this->get(SsoSettings::getUrl())->assertStatus($status);
})->with([
    'owner' => [SystemRole::Owner, 200],
    'admin' => [SystemRole::Admin, 200],
    'designer' => [SystemRole::Designer, 403],
    'reviewer' => [SystemRole::Reviewer, 403],
    'viewer' => [SystemRole::Viewer, 403],
]);

it('answers 404 for another organization', function (): void {
    $other = makeOrganization(name: 'Other');
    $this->actingAs($this->owner);

    $this->get('/admin/'.$other->slug.'/sso')->assertNotFound();
});

it('saves the provider with a write-only secret and shows the callback URL', function (): void {
    actingInAdmin($this->owner, $this->org);

    Livewire::test(SsoSettings::class)
        ->fillForm(['type' => 'oidc', 'name' => 'Acme Keycloak', 'flavor' => 'generic', 'issuer' => 'https://sso.acme.example/realms/acme',
            'client_id' => 'form-schema-app', 'client_secret' => 'Sup3r-Secret!', 'scopes' => ['openid', 'email'], 'jit_enabled' => true], 'providerForm')
        ->call('saveProvider')
        ->assertHasNoErrors()
        ->assertSet('provider.client_secret', null)
        ->assertDontSee('Sup3r-Secret!')
        ->assertSee(IdentityProvider::query()->sole()->callbackUrl())
        ->assertSee(__('sso_admin.status.states.draft'));

    expect(IdentityProvider::query()->sole())->client_secret->toBe('Sup3r-Secret!')->name->toBe('Acme Keycloak')
        ->and(Activity::query()->where('event', 'sso.provider_created')->sole()->causer_id)->toBe($this->owner->id);

    Livewire::test(SsoSettings::class)
        ->assertSet('provider.client_secret', null)
        ->assertSee(__('sso_admin.provider.help.secret_stored'))
        ->fillForm(['name' => 'Acme SSO'], 'providerForm')
        ->call('saveProvider')
        ->assertHasNoErrors();
    expect(IdentityProvider::query()->sole())->client_secret->toBe('Sup3r-Secret!')->name->toBe('Acme SSO');
});

it('shows validation errors of the domain action on the provider form', function (): void {
    actingInAdmin($this->owner, $this->org);

    Livewire::test(SsoSettings::class)
        ->fillForm(['type' => 'oidc', 'name' => 'X', 'flavor' => 'generic', 'issuer' => 'http://sso.acme.example',
            'client_id' => 'c', 'client_secret' => 's', 'scopes' => ['openid', 'bad"scope']], 'providerForm')
        ->call('saveProvider')
        ->assertHasErrors(['provider.issuer', 'provider.scopes']);

    Livewire::test(SsoSettings::class)
        ->fillForm(['type' => 'saml', 'name' => 'X'], 'providerForm')
        ->call('saveProvider')
        ->assertHasErrors(['provider.metadata_url']);

    expect(IdentityProvider::query()->count())->toBe(0);
});

it('adds, shows, verifies and removes domains', function (): void {
    actingInAdmin($this->owner, $this->org);

    $page = Livewire::test(SsoSettings::class)
        ->callTableAction('addDomain', data: ['domain' => 'ACME.example'])
        ->assertHasNoTableActionErrors()
        ->assertSee('acme.example');
    $domain = SsoDomain::query()->sole();

    $page->mountTableAction('dnsRecord', $domain)
        ->assertTableActionDataSet(['txt_name' => '_form-schema-app-verification.acme.example', 'txt_value' => $domain->txtValue()]);

    Livewire::test(SsoSettings::class)->callTableAction('addDomain', data: ['domain' => 'boss@acme.example'])
        ->assertHasTableActionErrors(['domain']);

    Livewire::test(SsoSettings::class)->callTableAction('verifyDomain', $domain)->assertNotified(__('sso_admin.domains.results.missing'));
    $this->dns->publish($domain);
    Livewire::test(SsoSettings::class)->callTableAction('verifyDomain', $domain)->assertNotified(__('sso_admin.domains.results.verified'));
    expect($domain->refresh())->isVerified()->toBeTrue()->last_check_result->toBe(DomainVerification::Verified);

    Livewire::test(SsoSettings::class)->callTableAction('removeDomain', $domain)->assertNotified();
    expect(SsoDomain::query()->count())->toBe(0);
});

it('syncs claim mappings and shows row errors', function (): void {
    actingInAdmin($this->owner, $this->org);
    $provider = IdentityProvider::factory()->create();
    $group = Group::factory()->create();
    $reviewer = Role::query()->where('organization_id', $this->org->id)->where('name', SystemRole::Reviewer->value)->sole();
    $owner = Role::query()->where('organization_id', $this->org->id)->where('name', SystemRole::Owner->value)->sole();
    $old = IdentityProviderMapping::factory()->create(['identity_provider_id' => $provider->id, 'value' => 'old', 'group_id' => $group->id]);

    Livewire::test(SsoSettings::class)
        ->fillForm(['rows' => [
            ['claim' => 'groups', 'value' => 'reviewers', 'target' => 'role:'.$reviewer->id],
            ['claim' => '', 'value' => 'crew', 'target' => 'group:'.$group->id],
        ]], 'mappingsForm')
        ->call('saveMappings')
        ->assertHasNoErrors();

    expect(IdentityProviderMapping::query()->orderBy('value')->get(['value', 'claim', 'role_id', 'group_id'])->toArray())->toBe([
        ['value' => 'crew', 'claim' => 'groups', 'role_id' => null, 'group_id' => $group->id],
        ['value' => 'reviewers', 'claim' => 'groups', 'role_id' => $reviewer->id, 'group_id' => null],
    ])->and(IdentityProviderMapping::query()->find($old->id))->toBeNull();

    $page = Livewire::test(SsoSettings::class);
    $page->fillForm(['rows' => [['claim' => 'groups', 'value' => 'owners', 'target' => 'role:'.$owner->id]]], 'mappingsForm')
        ->call('saveMappings');
    $errors = array_keys($page->errors()->toArray());
    expect($errors)->toHaveCount(1)->and($errors[0])->toEndWith('.target')->toStartWith('mappings.rows.')
        ->and(IdentityProviderMapping::query()->count())->toBe(2); // the failed save rolled back the deletions
});

it('switches enforcement on only when the preconditions hold and explains them', function (): void {
    $owner = ssoAdminPageOwnerWithMfa($this->owner);
    actingInAdmin($owner, $this->org);
    $provider = IdentityProvider::factory()->create();

    Livewire::test(SsoSettings::class)
        ->assertSee(__('sso_admin.enforcement.unmet', ['condition' => __('sso_admin.enforcement.conditions.verified_domain')]))
        ->fillForm(['enforce_sso' => true, 'break_glass_user_id' => $owner->id], 'enforcementForm')
        ->call('saveEnforcement')
        ->assertHasErrors(['enforcement.enforce_sso']);
    expect($provider->refresh()->enforce_sso)->toBeFalse();

    SsoDomain::factory()->verified()->create(['domain' => 'acme.example']);
    $provider->forceFill(['status' => IdentityProviderStatus::Active])->save();

    Livewire::test(SsoSettings::class)
        ->assertSee(__('sso_admin.enforcement.met', ['condition' => __('sso_admin.enforcement.conditions.verified_domain')]))
        ->fillForm(['enforce_sso' => true, 'break_glass_user_id' => $owner->id], 'enforcementForm')
        ->call('saveEnforcement')
        ->assertHasNoErrors()
        ->assertNotified(__('sso_admin.enforcement.saved'));
    expect($provider->refresh())->enforce_sso->toBeTrue()->break_glass_user_id->toBe($owner->id);
});

it('refuses an Admin without a linked identity as actor (self-lockout)', function (): void {
    $owner = ssoAdminPageOwnerWithMfa($this->owner);
    $admin = memberOf($this->org, SystemRole::Admin);
    actingInAdmin($admin, $this->org);
    $provider = IdentityProvider::factory()->active()->create();
    SsoDomain::factory()->verified()->create(['domain' => 'acme.example']);

    $page = Livewire::test(SsoSettings::class)
        ->fillForm(['enforce_sso' => true, 'break_glass_user_id' => $owner->id], 'enforcementForm')
        ->call('saveEnforcement')
        ->assertHasErrors(['enforcement.enforce_sso']);
    expect($page->errors()->get('enforcement.enforce_sso'))->toBe([__('sso_admin.enforcement.errors.self_lockout')]);

    ExternalIdentity::factory()->create(['identity_provider_id' => $provider->id, 'user_id' => $admin->id]);
    Livewire::test(SsoSettings::class)
        ->fillForm(['enforce_sso' => true, 'break_glass_user_id' => $owner->id], 'enforcementForm')
        ->call('saveEnforcement')
        ->assertHasNoErrors();
});

it('links the test sign-in to the SSO redirect in a new tab and deletes the provider', function (): void {
    actingInAdmin($this->owner, $this->org);
    $provider = IdentityProvider::factory()->create();

    Livewire::test(SsoSettings::class)
        ->assertActionHasUrl('testLogin', $provider->redirectUrl(SsoClient::Admin, test: true))
        ->assertActionShouldOpenUrlInNewTab('testLogin')
        ->callAction('deleteProvider')
        ->assertNotified(__('sso_admin.actions.deleted'));

    expect(IdentityProvider::query()->count())->toBe(0);
});

it('shows the SAML service provider URLs to register at the identity provider', function (): void {
    actingInAdmin($this->owner, $this->org);
    $provider = IdentityProvider::factory()->saml()->create(['last_error' => 'Metadata unreachable', 'last_error_at' => now()]);

    $this->get(SsoSettings::getUrl())->assertOk()
        ->assertSee($provider->samlAcsUrl())
        ->assertSee($provider->samlEntityId())
        ->assertSee('Metadata unreachable')
        ->assertDontSee($provider->callbackUrl());
});
