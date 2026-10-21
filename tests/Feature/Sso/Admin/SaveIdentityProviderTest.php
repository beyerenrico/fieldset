<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Sso\Admin\DeleteIdentityProvider;
use App\Domain\Identity\Sso\Admin\SaveIdentityProvider;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

const SSO_ADMIN_ENTRA_TENANT = '0b3f8e7e-4a51-4c1f-9d2e-6f0a1b2c3d4e';

/** @param  array<string, mixed>  $config */
function ssoAdminSaveProvider(array $config, ?string $secret = 'client-secret-1', IdentityProviderType $type = IdentityProviderType::Oidc, string $name = 'Company SSO'): IdentityProvider
{
    $org = test()->org;

    return app(SaveIdentityProvider::class)->handle($org, $type, $name, $config, $secret, ownerOf($org));
}

/** @return array<string, list<string>> */
function ssoAdminProviderErrors(Closure $save): array
{
    try {
        $save();
    } catch (ValidationException $e) {
        return $e->errors();
    }
    test()->fail('expected a validation error');
}

function ssoAdminGenericConfig(array $overrides = []): array
{
    return ['flavor' => 'generic', 'issuer' => 'https://idp.acme.example/realms/acme/', 'client_id' => 'fieldset', ...$overrides];
}

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->tenant = app(TenantContext::class);
});

it('creates a generic OIDC provider with normalized config, encrypted secret and one audit entry with key names only', function (): void {
    $provider = ssoAdminSaveProvider(ssoAdminGenericConfig(['scopes' => 'email profile groups', 'jit_enabled' => true]), 'Sup3r-Secret!');

    $entry = Activity::query()->where('subject_type', (new IdentityProvider)->getMorphClass())->sole();
    expect($provider)
        ->status->toBe(IdentityProviderStatus::Draft)
        ->client_secret->toBe('Sup3r-Secret!')
        ->jit_enabled->toBeTrue()
        ->and($provider->config)->toMatchArray([
            'flavor' => 'generic', 'issuer' => 'https://idp.acme.example/realms/acme', 'client_id' => 'fieldset',
            'scopes' => ['openid', 'email', 'profile', 'groups'], 'groups_claim' => 'groups', 'trust_unverified_email' => false,
        ])
        ->and($entry->event)->toBe('sso.provider_created')
        ->and($entry->properties['changed'])->toContain('name', 'client_secret', 'config.issuer', 'jit_enabled')
        ->and(json_encode($entry->properties))->not->toContain('Sup3r-Secret!')->not->toContain('idp.acme.example');
});

it('derives the issuer for Entra and Google and requires a tenant GUID for Entra', function (): void {
    $entra = ssoAdminSaveProvider(['flavor' => 'entra', 'tenant' => strtoupper(SSO_ADMIN_ENTRA_TENANT), 'client_id' => 'abc', 'issuer' => 'https://evil.example']);
    expect($entra->config)->toMatchArray(['tenant' => SSO_ADMIN_ENTRA_TENANT, 'issuer' => 'https://login.microsoftonline.com/'.SSO_ADMIN_ENTRA_TENANT.'/v2.0']);

    foreach (['common', 'organizations', 'contoso.onmicrosoft.com', ''] as $tenant) {
        expect(ssoAdminProviderErrors(fn () => ssoAdminSaveProvider(['flavor' => 'entra', 'tenant' => $tenant, 'client_id' => 'abc'])))->toHaveKey('tenant');
    }

    $google = ssoAdminSaveProvider(['flavor' => 'google', 'client_id' => 'abc.apps.googleusercontent.com']);
    expect($google->config)->toMatchArray(['issuer' => 'https://accounts.google.com', 'tenant' => null]);
});

it('requires an https issuer unless insecure IdPs are allowed for development', function (): void {
    foreach (['http://idp.acme.example', 'https://user:pw@idp.acme.example', 'https://idp.acme.example/?a=1', 'not a url', ''] as $issuer) {
        expect(ssoAdminProviderErrors(fn () => ssoAdminSaveProvider(ssoAdminGenericConfig(['issuer' => $issuer]))))->toHaveKey('issuer');
    }

    config(['sso.allow_insecure_idp' => true]);
    expect(ssoAdminSaveProvider(ssoAdminGenericConfig(['issuer' => 'http://keycloak:8080/realms/fieldset']))->config['issuer'])
        ->toBe('http://keycloak:8080/realms/fieldset');
});

it('validates the client id, scopes, groups claim and the flavor', function (): void {
    expect(ssoAdminProviderErrors(fn () => ssoAdminSaveProvider(ssoAdminGenericConfig(['client_id' => '']))))->toHaveKey('client_id')
        ->and(ssoAdminProviderErrors(fn () => ssoAdminSaveProvider(ssoAdminGenericConfig(['scopes' => ['openid', 'bad"scope']]))))->toHaveKey('scopes.1')
        ->and(ssoAdminProviderErrors(fn () => ssoAdminSaveProvider(ssoAdminGenericConfig(['groups_claim' => 'has space']))))->toHaveKey('groups_claim')
        ->and(ssoAdminProviderErrors(fn () => ssoAdminSaveProvider(ssoAdminGenericConfig(['flavor' => 'okta']))))->toHaveKey('flavor')
        ->and(ssoAdminProviderErrors(fn () => ssoAdminSaveProvider(ssoAdminGenericConfig(), name: '')))->toHaveKey('name');
});

it('keeps the write-only secret when none is entered and requires one for a new OIDC provider', function (): void {
    expect(ssoAdminProviderErrors(fn () => ssoAdminSaveProvider(ssoAdminGenericConfig(), null)))->toHaveKey('client_secret');

    ssoAdminSaveProvider(ssoAdminGenericConfig(), 'first-secret');
    $kept = ssoAdminSaveProvider(ssoAdminGenericConfig(['groups_claim' => 'roles']), null);
    expect($kept->client_secret)->toBe('first-secret')
        ->and(Activity::query()->where('event', 'sso.provider_updated')->sole()->properties['changed'])->toBe(['config.groups_claim']);

    $rotated = ssoAdminSaveProvider(ssoAdminGenericConfig(['groups_claim' => 'roles']), 'second-secret');
    expect($rotated->client_secret)->toBe('second-secret')
        ->and(Activity::query()->where('event', 'sso.provider_updated')->latest('id')->first()->properties['changed'])->toBe(['client_secret']);

    ssoAdminSaveProvider(ssoAdminGenericConfig(['groups_claim' => 'roles']), null); // nothing changed: no entry
    expect(Activity::query()->where('event', 'sso.provider_updated')->count())->toBe(2);
});

it('accepts SAML metadata by URL or pasted XML and drops any secret', function (): void {
    $saml = ssoAdminSaveProvider(['metadata_url' => 'https://idp.acme.example/saml/descriptor', 'email_attribute' => 'email'], 'ignored', IdentityProviderType::Saml);
    expect($saml)->type->toBe(IdentityProviderType::Saml)->client_secret->toBeNull()
        ->and($saml->config)->toBe(['metadata_url' => 'https://idp.acme.example/saml/descriptor', 'metadata_xml' => null,
            'email_attribute' => 'email', 'name_attribute' => null, 'groups_attribute' => null,
            'name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent', 'subject_attribute' => null]); // gate review: persistent by default

    $xml = '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="https://idp.acme.example">'
        .'<md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol"/></md:EntityDescriptor>';
    expect(ssoAdminSaveProvider(['metadata_xml' => $xml], null, IdentityProviderType::Saml)->config['metadata_xml'])->toBe($xml);
});

it('refuses SAML without metadata, with insecure URLs, DTDs or non-IdP XML', function (string $key, string $value): void {
    expect(ssoAdminProviderErrors(fn () => ssoAdminSaveProvider([$key => $value], null, IdentityProviderType::Saml)))->toHaveKey($key);
})->with([
    'nothing' => ['metadata_url', ''],
    'http URL' => ['metadata_url', 'http://idp.acme.example/descriptor'],
    'not XML' => ['metadata_xml', 'hello'],
    'DTD (XXE)' => ['metadata_xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata">&e;</md:EntityDescriptor>'],
    'SP metadata' => ['metadata_xml', '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"><md:SPSSODescriptor/></md:EntityDescriptor>'],
]);

it('only accepts the organization\'s own non-Owner roles and groups as JIT defaults', function (): void {
    $this->tenant->set($this->org);
    $owner = Role::query()->where('organization_id', $this->org->id)->where('name', SystemRole::Owner->value)->sole();
    $worker = Role::query()->where('organization_id', $this->org->id)->where('name', SystemRole::FieldWorker->value)->sole();
    $group = Group::factory()->create();
    $other = makeOrganization(name: 'Other');
    $foreignRole = Role::query()->where('organization_id', $other->id)->where('name', SystemRole::FieldWorker->value)->sole();
    $foreignGroup = $this->tenant->run($other, fn () => Group::factory()->create());
    $this->tenant->set($this->org);

    expect(ssoAdminProviderErrors(fn () => ssoAdminSaveProvider(ssoAdminGenericConfig(['default_role_id' => $owner->id]))))->toHaveKey('default_role_id')
        ->and(ssoAdminProviderErrors(fn () => ssoAdminSaveProvider(ssoAdminGenericConfig(['default_role_id' => $foreignRole->id]))))->toHaveKey('default_role_id')
        ->and(ssoAdminProviderErrors(fn () => ssoAdminSaveProvider(ssoAdminGenericConfig(['default_group_id' => $foreignGroup->id]))))->toHaveKey('default_group_id');

    $provider = ssoAdminSaveProvider(ssoAdminGenericConfig(['default_role_id' => $worker->id, 'default_group_id' => $group->id, 'force_reauth' => true]));
    expect($provider)->default_role_id->toBe($worker->id)->default_group_id->toBe($group->id)->force_reauth->toBeTrue();
});

it('needs a new test login after a connection change and refuses it while SSO is enforced', function (): void {
    ssoAdminSaveProvider(ssoAdminGenericConfig());
    $provider = IdentityProvider::query()->withoutTenant()->sole();
    $provider->forceFill(['status' => IdentityProviderStatus::Active])->save();

    expect(ssoAdminSaveProvider(ssoAdminGenericConfig(), 'rotated')->status)->toBe(IdentityProviderStatus::Active); // secret rotation keeps the status
    $changed = ssoAdminSaveProvider(ssoAdminGenericConfig(['issuer' => 'https://new-idp.acme.example']), null);
    expect($changed->status)->toBe(IdentityProviderStatus::Draft)
        ->and(Activity::query()->where('event', 'sso.provider_updated')->latest('id')->first()->properties['changed'])->toBe(['status', 'config.issuer']);

    $changed->forceFill(['status' => IdentityProviderStatus::Active, 'enforce_sso' => true, 'break_glass_user_id' => ownerOf($this->org)->id])->save();
    expect(ssoAdminProviderErrors(fn () => ssoAdminSaveProvider(ssoAdminGenericConfig(['issuer' => 'https://new-idp.acme.example', 'client_id' => 'other-client']), null)))->toHaveKey('client_id')
        ->and(ssoAdminSaveProvider(ssoAdminGenericConfig(['issuer' => 'https://new-idp.acme.example']), 'rotated-again')->client_secret)->toBe('rotated-again')
        ->and(ssoAdminProviderErrors(fn () => ssoAdminSaveProvider(['metadata_url' => 'https://idp.acme.example/d'], null, IdentityProviderType::Saml)))->toHaveKey('type');
});

it('deletes a provider with one audit entry, but not while SSO is enforced', function (): void {
    $provider = ssoAdminSaveProvider(ssoAdminGenericConfig());
    $provider->forceFill(['enforce_sso' => true])->save();
    expect(fn () => app(DeleteIdentityProvider::class)->handle($provider, ownerOf($this->org)))->toThrow(ValidationException::class);

    $provider->forceFill(['enforce_sso' => false])->save();
    app(DeleteIdentityProvider::class)->handle($provider, ownerOf($this->org));

    expect(IdentityProvider::query()->withoutTenant()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'sso.provider_deleted')->sole()->properties['type'])->toBe('oidc')
        ->and(Activity::query()->where('subject_type', (new IdentityProvider)->getMorphClass())->where('event', 'deleted')->count())->toBe(0);
});
