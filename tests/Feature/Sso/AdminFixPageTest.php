<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Login\SsoLoginResponder;
use App\Domain\Notifications\FakeNotifier;
use App\Domain\Notifications\Notifier;
use App\Filament\Admin\Pages\Sso\SsoSettings;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\Sso\FakeTxtRecordResolver;

/* Gate review M7, FS-admin items 5, 7, 9, 10, 11, 12, 13 (UI): the SSO settings page. */

function adminFixPageOwner(User $owner): User
{
    $owner->forceFill(['name' => 'Berta Boss', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();

    return $owner->refresh();
}

function adminFixEnforcementAction(): TestAction
{
    return TestAction::make('saveEnforcement')->schemaComponent('enforcementForm-actions', 'content');
}

beforeEach(function (): void {
    app()->instance(Notifier::class, new FakeNotifier);
    FakeTxtRecordResolver::bind();
    $this->org = makeOrganization();
    $this->owner = adminFixPageOwner(ownerOf($this->org));
    actingInAdmin($this->owner, $this->org);
});

it('shows a successful test sign-in with the groups received and the refreshed status', function (): void {
    $provider = IdentityProvider::factory()->active()->create();
    session()->flash(SsoLoginResponder::TEST_RESULT_FLASH, ['ok' => true, 'provider' => $provider->uuid, 'profile' => [
        'email' => 'probe@acme.example', 'email_verified' => true, 'subject' => 'kc-6', 'issuer' => 'https://idp.example.test/realms/acme',
        'name' => 'Probe', 'claims' => ['groups' => ['crew', 'form-reviewers']],
    ]]);

    Livewire::test(SsoSettings::class)
        ->assertNotified(__('sso_admin.test_result.succeeded', ['email' => 'probe@acme.example', 'groups' => 'crew, form-reviewers']))
        ->assertSee(__('sso_admin.status.states.active'))
        ->assertSee(__('sso_admin.actions.test_login_help'));
});

it('says when no groups arrived and explains a failed test sign-in', function (): void {
    $provider = IdentityProvider::factory()->create();
    session()->flash(SsoLoginResponder::TEST_RESULT_FLASH, ['ok' => true, 'provider' => $provider->uuid, 'profile' => [
        'email' => 'probe@acme.example', 'email_verified' => false, 'subject' => 'kc-6', 'issuer' => 'x', 'name' => null, 'claims' => [],
    ]]);
    Livewire::test(SsoSettings::class)
        ->assertNotified(__('sso_admin.test_result.succeeded', ['email' => 'probe@acme.example', 'groups' => __('sso_admin.test_result.no_groups')]));

    session()->flash(SsoLoginResponder::TEST_RESULT_FLASH, ['ok' => false, 'provider' => $provider->uuid, 'error' => 'provider_unavailable', 'detail' => 'GuzzleHttp: timeout']);
    Livewire::test(SsoSettings::class)
        ->assertNotified(__('sso_admin.test_result.failed', ['reason' => __('sso_admin.login.refused.provider_unavailable')]));

    session()->flash(SsoLoginResponder::TEST_RESULT_FLASH, ['ok' => false, 'provider' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'error' => 'sso_failed']);
    Livewire::test(SsoSettings::class)->assertNotNotified();
});

it('confirms switching enforcement on, naming the domains, the provider and the break-glass Owner', function (): void {
    $provider = IdentityProvider::factory()->active()->create(['name' => 'Acme Keycloak']);
    SsoDomain::factory()->verified()->create(['domain' => 'acme.example']);
    SsoDomain::factory()->verified()->create(['domain' => 'acme-group.example']);

    Livewire::test(SsoSettings::class)
        ->fillForm(['enforce_sso' => true, 'break_glass_user_id' => $this->owner->id], 'enforcementForm')
        ->mountAction(adminFixEnforcementAction())
        ->assertActionMounted(adminFixEnforcementAction())
        ->assertMountedActionModalSee([
            __('sso_admin.enforcement.confirm_heading'),
            __('sso_admin.enforcement.confirm_body', ['domains' => 'acme-group.example, acme.example', 'provider' => 'Acme Keycloak', 'owner' => 'Berta Boss']),
        ])
        ->callMountedAction()
        ->assertHasNoErrors()
        ->assertNotified(__('sso_admin.enforcement.saved'));

    expect($provider->refresh()->enforce_sso)->toBeTrue();
});

it('saves without a confirmation when enforcement is not switched on, or reports what is missing', function (): void {
    $provider = IdentityProvider::factory()->create(); // draft: a precondition is missing

    Livewire::test(SsoSettings::class)
        ->fillForm(['enforce_sso' => true, 'break_glass_user_id' => $this->owner->id], 'enforcementForm')
        ->callAction(adminFixEnforcementAction())
        ->assertHasErrors(['enforcement.enforce_sso']);

    Livewire::test(SsoSettings::class)
        ->fillForm(['enforce_sso' => false, 'break_glass_user_id' => $this->owner->id], 'enforcementForm')
        ->callAction(adminFixEnforcementAction())
        ->assertHasNoErrors()
        ->assertNotified(__('sso_admin.enforcement.saved'));

    expect($provider->refresh())->enforce_sso->toBeFalse()->break_glass_user_id->toBe($this->owner->id);
});

it('shows the addresses for the identity provider before the first save and keeps them', function (): void {
    $page = Livewire::test(SsoSettings::class);
    $uuid = $page->get('pendingUuid');
    $pending = (new IdentityProvider)->forceFill(['uuid' => $uuid]);

    $page->assertSee(__('sso_admin.status.addresses_title'))
        ->assertSee($pending->callbackUrl())
        ->assertDontSee($pending->samlAcsUrl())
        ->fillForm(['type' => 'saml'], 'providerForm')
        ->assertSee($pending->samlAcsUrl())
        ->assertSee($pending->samlEntityId())
        ->fillForm(['type' => 'oidc', 'name' => 'Acme', 'flavor' => 'generic', 'issuer' => 'https://sso.acme.example/realms/acme',
            'client_id' => 'app', 'client_secret' => 'secret'], 'providerForm')
        ->call('saveProvider')
        ->assertHasNoErrors();

    expect(IdentityProvider::query()->sole()->uuid)->toBe($uuid);
});

it('shows one translated sentence per last error with the raw detail below', function (): void {
    $provider = IdentityProvider::factory()->create([
        'status' => IdentityProviderStatus::Error, 'last_error' => 'invalid_client: GuzzleHttp\Exception\ClientException: 401 invalid_client', 'last_error_at' => now(),
    ]);

    Livewire::test(SsoSettings::class)
        ->assertSee(__('sso_admin.status.error_reasons.invalid_client'))
        ->assertSee('401 invalid_client');

    $provider->forceFill(['last_error' => 'something_new: odd detail'])->save();
    Livewire::test(SsoSettings::class)
        ->assertSee(__('sso_admin.status.error_reasons.other'))
        ->assertSee('something_new: odd detail');
});

it('explains the TXT record name for DNS providers that append the domain', function (): void {
    $domain = SsoDomain::factory()->create(['domain' => 'acme.example']);

    Livewire::test(SsoSettings::class)
        ->mountTableAction('dnsRecord', $domain)
        ->assertMountedActionModalSee(__('sso_admin.domains.dns_provider_note'));
});

it('explains the protocol and warns about unstable SAML NameID formats', function (): void {
    Livewire::test(SsoSettings::class)
        ->assertSee(__('sso_admin.provider.help.type'))
        ->fillForm(['type' => 'saml'], 'providerForm')
        ->assertSee(__('sso_admin.provider.help.name_id_format'))
        ->assertSchemaStateSet(['name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent'], 'providerForm')
        ->fillForm(['name' => 'Acme SAML', 'metadata_url' => 'https://idp.acme.example/saml',
            'name_id_format' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress'], 'providerForm')
        ->assertSee(__('sso_admin.provider.help.name_id_format_unstable'))
        ->call('saveProvider')
        ->assertHasErrors(['provider.subject_attribute'])
        ->fillForm(['subject_attribute' => 'objectId'], 'providerForm')
        ->call('saveProvider')
        ->assertHasNoErrors();

    expect(IdentityProvider::query()->sole()->config['subject_attribute'])->toBe('objectId');
});

it('leaves the default role to Owners', function (): void {
    IdentityProvider::factory()->create();
    Livewire::test(SsoSettings::class)->assertFormFieldEnabled('default_role_id', 'providerForm');

    actingInAdmin(memberOf($this->org, SystemRole::Admin), $this->org);
    Livewire::test(SsoSettings::class)
        ->assertFormFieldDisabled('default_role_id', 'providerForm')
        ->assertFormFieldDisabled('type', 'providerForm')
        ->assertFormFieldEnabled('name', 'providerForm')
        ->assertFormFieldEnabled('client_secret', 'providerForm');
});
