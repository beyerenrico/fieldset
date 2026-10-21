<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Tenancy\TenantContext;
use Database\Seeders\SsoDemoSeeder;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

/** @return array{provider: IdentityProvider, domains: list<SsoDomain>, mappings: list<array{string, string, string}>} */
function ssoDemoRecords(string $slug): array
{
    $org = Organization::query()->where('slug', $slug)->sole();

    return app(TenantContext::class)->run($org, fn (): array => [
        'provider' => IdentityProvider::query()->sole(),
        'domains' => SsoDomain::query()->get()->all(),
        'mappings' => IdentityProviderMapping::query()->with('role')->get()
            ->map(fn (IdentityProviderMapping $m): array => [$m->claim, $m->value, (string) $m->role?->name])->all(),
    ]);
}

/** @return list<string> */
function ssoDemoRoles(User $user, Organization $org): array
{
    return Role::query()->join('model_has_roles as mhr', 'mhr.role_id', '=', 'roles.id')
        ->where('mhr.model_id', $user->id)->where('mhr.organization_id', $org->id)
        ->orderBy('roles.name')->pluck('roles.name')->map(fn (mixed $n): string => (string) $n)->all();
}

/** @return array<string, mixed> */
function keycloakRealm(): array
{
    return json_decode((string) file_get_contents(base_path('docker/keycloak/realm-fieldset.json')), true, flags: JSON_THROW_ON_ERROR);
}

it('seeds SSO Demo GmbH: verified domain, active Keycloak OIDC provider, JIT, mapping and enforcement', function (): void {
    $this->seed(SsoDemoSeeder::class);

    $org = Organization::query()->where('slug', 'sso-demo')->sole();
    ['provider' => $provider, 'domains' => $domains, 'mappings' => $mappings] = ssoDemoRecords('sso-demo');
    $owner = User::query()->where('email', SsoDemoSeeder::BREAK_GLASS_EMAIL)->sole();

    expect($org->name)->toBe('SSO Demo GmbH')
        ->and($provider->uuid)->toBe(SsoDemoSeeder::OIDC_PROVIDER_UUID)
        ->and($provider->type)->toBe(IdentityProviderType::Oidc)
        ->and($provider->status)->toBe(IdentityProviderStatus::Active)
        ->and($provider->config)->toMatchArray([
            'flavor' => 'generic',
            'issuer' => SsoDemoSeeder::KEYCLOAK_REALM_URL,
            'client_id' => SsoDemoSeeder::OIDC_CLIENT_ID,
        ])
        ->and($provider->client_secret)->toBe(SsoDemoSeeder::OIDC_CLIENT_SECRET)
        ->and($provider->jit_enabled)->toBeTrue()
        ->and($provider->defaultRole?->name)->toBe(SystemRole::FieldWorker->value)
        ->and($provider->enforce_sso)->toBeTrue()
        ->and($provider->break_glass_user_id)->toBe($owner->id)
        ->and(array_map(fn (SsoDomain $d): array => [$d->domain, $d->isVerified()], $domains))->toBe([['sso-demo.test', true]])
        ->and($domains[0]->verification_token)->toBe(SsoDemoSeeder::DOMAIN_TOKENS['sso-demo.test'])
        ->and($mappings)->toBe([['groups', 'form-designers', SystemRole::Designer->value]]);
});

it('gives the break-glass Owner a password and a confirmed TOTP secret that E2E can compute', function (): void {
    $this->seed(SsoDemoSeeder::class);

    $org = Organization::query()->where('slug', 'sso-demo')->sole();
    $owner = User::query()->where('email', SsoDemoSeeder::BREAK_GLASS_EMAIL)->sole();
    $secret = $owner->getAppAuthenticationSecret();

    expect(Hash::check('password', $owner->password))->toBeTrue()
        ->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and(ssoDemoRoles($owner, $org))->toBe([SystemRole::Owner->value])
        ->and($owner->two_factor_confirmed_at)->not->toBeNull()
        ->and($secret)->toBe(SsoDemoSeeder::BREAK_GLASS_TOTP_SECRET)
        ->and((new Google2FA)->verifyKey((string) $secret, (new Google2FA)->getCurrentOtp((string) $secret)))->toBeTrue();
});

it('seeds a password user on the enforced domain for the sso_required checks', function (): void {
    $this->seed(SsoDemoSeeder::class);

    $org = Organization::query()->where('slug', 'sso-demo')->sole();
    $legacy = User::query()->where('email', SsoDemoSeeder::LEGACY_EMAIL)->sole();

    expect(Hash::check('password', $legacy->password))->toBeTrue()
        ->and(ssoDemoRoles($legacy, $org))->toBe([SystemRole::FieldWorker->value])
        ->and($legacy->hasPermissionIn($org, Permission::FormsFill))->toBeTrue();
});

it('seeds SAML Demo GmbH with the Keycloak SAML descriptor, JIT and the reviewer mapping, no enforcement', function (): void {
    $this->seed(SsoDemoSeeder::class);

    ['provider' => $provider, 'domains' => $domains, 'mappings' => $mappings] = ssoDemoRecords('saml-demo');

    expect(Organization::query()->where('slug', 'saml-demo')->sole()->name)->toBe('SAML Demo GmbH')
        ->and($provider->uuid)->toBe(SsoDemoSeeder::SAML_PROVIDER_UUID)
        ->and($provider->type)->toBe(IdentityProviderType::Saml)
        ->and($provider->status)->toBe(IdentityProviderStatus::Active)
        ->and($provider->config)->toMatchArray([
            'metadata_url' => SsoDemoSeeder::KEYCLOAK_REALM_URL.'/protocol/saml/descriptor',
            'email_attribute' => 'email',
            'groups_attribute' => 'groups',
        ])
        ->and($provider->client_secret)->toBeNull()
        ->and($provider->jit_enabled)->toBeTrue()
        ->and($provider->enforce_sso)->toBeFalse()
        ->and($provider->break_glass_user_id)->toBeNull()
        ->and(array_map(fn (SsoDomain $d): array => [$d->domain, $d->isVerified()], $domains))->toBe([['saml-demo.test', true]])
        ->and($mappings)->toBe([['groups', 'form-reviewers', SystemRole::Reviewer->value]]);
});

it('is idempotent', function (): void {
    $this->seed(SsoDemoSeeder::class);
    $this->seed(SsoDemoSeeder::class);

    expect(Organization::query()->whereIn('slug', ['sso-demo', 'saml-demo'])->count())->toBe(2)
        ->and(IdentityProvider::query()->withoutTenant()->count())->toBe(2)
        ->and(SsoDomain::query()->withoutTenant()->count())->toBe(2)
        ->and(IdentityProviderMapping::query()->withoutTenant()->count())->toBe(2)
        ->and(User::query()->whereIn('email', [SsoDemoSeeder::BREAK_GLASS_EMAIL, SsoDemoSeeder::LEGACY_EMAIL])->count())->toBe(2);
});

it('matches the Keycloak realm: redirect URI, SAML entity id and ACS, client secret', function (): void {
    $this->seed(SsoDemoSeeder::class);

    $clients = collect(keycloakRealm()['clients'])->keyBy('protocol');
    $path = fn (string $url): string => (string) parse_url($url, PHP_URL_PATH);
    ['provider' => $oidc] = ssoDemoRecords('sso-demo');
    ['provider' => $saml] = ssoDemoRecords('saml-demo');

    expect(array_map($path, $clients['openid-connect']['redirectUris']))->toBe([$path($oidc->callbackUrl())])
        ->and($clients['openid-connect']['clientId'])->toBe(SsoDemoSeeder::OIDC_CLIENT_ID)
        ->and($clients['openid-connect']['secret'])->toBe(SsoDemoSeeder::OIDC_CLIENT_SECRET)
        ->and($clients['openid-connect']['attributes']['pkce.code.challenge.method'])->toBe('S256')
        ->and($path($clients['saml']['clientId']))->toBe($path($saml->samlEntityId()))
        ->and(array_map($path, $clients['saml']['redirectUris']))->toBe([$path($saml->samlAcsUrl())])
        ->and($path($clients['saml']['attributes']['saml_assertion_consumer_url_post']))->toBe($path($saml->samlAcsUrl()));
});

it('has the demo users in the realm: verified emails, names, no required actions, groups', function (): void {
    $users = collect(keycloakRealm()['users'])->keyBy('email');

    expect($users->keys()->sort()->values()->all())->toBe([
        'legacy@sso-demo.test', 'saml.reviewer@saml-demo.test', 'sso.designer@sso-demo.test', 'sso.worker@sso-demo.test',
    ])
        ->and($users->every(fn (array $u): bool => $u['emailVerified'] === true && $u['requiredActions'] === []
            && $u['firstName'] !== '' && $u['lastName'] !== '' && $u['credentials'][0]['value'] === 'password'))->toBeTrue()
        ->and($users['sso.designer@sso-demo.test']['groups'])->toBe(['/form-designers'])
        ->and($users['saml.reviewer@saml-demo.test']['groups'])->toBe(['/form-reviewers']);
});
