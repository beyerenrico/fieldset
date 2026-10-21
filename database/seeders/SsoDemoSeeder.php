<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Actions\CreateOrganization;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enums\DomainVerification;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Enums\OidcFlavor;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * SSO demo data for development and E2E (M7, ADR 0036), against the Keycloak realm in
 * docker/keycloak/realm-fieldset.json (same provider uuids, client id and secret):
 * - "SSO Demo GmbH": verified domain sso-demo.test, OIDC provider with JIT (Field Worker), the mapping
 *   groups=form-designers → Designer and enforced SSO with the break-glass Owner sso-owner@demo.test
 *   (password "password", confirmed TOTP with a fixed secret, see tests/e2e/support/totp.ts);
 * - "SAML Demo GmbH": verified domain saml-demo.test, SAML provider with JIT, groups=form-reviewers →
 *   Reviewer, no enforcement.
 * Records are written through the models (enforcement columns directly, the preconditions hold by
 * construction). Idempotent per organization; never runs in production (DatabaseSeeder guards this).
 */
final class SsoDemoSeeder extends Seeder
{
    public const string OIDC_PROVIDER_UUID = '0b9f2d6e-5c1a-4e8b-9f3d-7a2c4e6b8d10';

    public const string SAML_PROVIDER_UUID = '3c7e9a1b-6d2f-4b8a-8e5c-1f4a7d9b2e60';

    /** The realm's issuer (KC_HOSTNAME in compose.dev.yaml); the containers reach it through the host gateway. */
    public const string KEYCLOAK_REALM_URL = 'http://keycloak.localhost:8180/realms/fieldset';

    public const string OIDC_CLIENT_ID = 'fieldset';

    /** Development-only secret of the Keycloak dev realm (docker/keycloak). */
    public const string OIDC_CLIENT_SECRET = 'fieldset-dev-oidc-secret';

    public const string BREAK_GLASS_EMAIL = 'sso-owner@demo.test';

    /** Base32 TOTP secret of the break-glass Owner; E2E computes its codes (RFC 6238). */
    public const string BREAK_GLASS_TOTP_SECRET = 'KRSXG5CTMVRXEZLUKRSXG5CTMVRXEZLU';

    public const string SAML_OWNER_EMAIL = 'saml-owner@demo.test';

    /** A local password account on the enforced domain: its password login must be refused. */
    public const string LEGACY_EMAIL = 'legacy@sso-demo.test';

    /** Fixed TXT tokens, so SSO_DNS_RECORDS can keep the demo domains verified in dev and CI. */
    public const array DOMAIN_TOKENS = [
        'sso-demo.test' => 'c0ffee0000000000000000000000000000550001',
        'saml-demo.test' => 'c0ffee0000000000000000000000000000550002',
    ];

    public function __construct(
        private readonly CreateOrganization $create,
        private readonly AddMember $add,
        private readonly TenantContext $tenant,
    ) {}

    public function run(): void
    {
        $this->ssoDemo();
        $this->samlDemo();
    }

    private function ssoDemo(): void
    {
        if (Organization::query()->where('slug', 'sso-demo')->exists()) {
            return;
        }

        $owner = $this->user(self::BREAK_GLASS_EMAIL, 'Sören Owner');
        $owner->saveAppAuthenticationSecret(self::BREAK_GLASS_TOTP_SECRET); // confirmed TOTP (ADR 0016)
        $org = $this->create->handle(['name' => 'SSO Demo GmbH', 'slug' => 'sso-demo', 'locale' => 'de'], $owner);
        $this->add->handle($org, $this->user(self::LEGACY_EMAIL, 'Lea Legacy'), [SystemRole::FieldWorker]);

        $this->tenant->run($org, function () use ($org, $owner): void {
            $this->verifiedDomain('sso-demo.test', $owner);
            $provider = $this->provider($org, self::OIDC_PROVIDER_UUID, IdentityProviderType::Oidc, 'Keycloak (OIDC)', [
                'flavor' => OidcFlavor::Generic->value,
                'issuer' => self::KEYCLOAK_REALM_URL,
                'client_id' => self::OIDC_CLIENT_ID,
                'scopes' => ['openid', 'email', 'profile'],
                'groups_claim' => 'groups',
            ], self::OIDC_CLIENT_SECRET);
            $provider->forceFill(['enforce_sso' => true, 'break_glass_user_id' => $owner->id])->save();
            $this->mapping($org, $provider, 'form-designers', SystemRole::Designer);
        });
    }

    private function samlDemo(): void
    {
        if (Organization::query()->where('slug', 'saml-demo')->exists()) {
            return;
        }

        $owner = $this->user(self::SAML_OWNER_EMAIL, 'Sascha Owner');
        $org = $this->create->handle(['name' => 'SAML Demo GmbH', 'slug' => 'saml-demo', 'locale' => 'de'], $owner);

        $this->tenant->run($org, function () use ($org, $owner): void {
            $this->verifiedDomain('saml-demo.test', $owner);
            $provider = $this->provider($org, self::SAML_PROVIDER_UUID, IdentityProviderType::Saml, 'Keycloak (SAML)', [
                'metadata_url' => self::KEYCLOAK_REALM_URL.'/protocol/saml/descriptor',
                'email_attribute' => 'email',
                'groups_attribute' => 'groups',
                // Keycloak sends an email NameID; the immutable user id (kc_user_id mapper) is the subject.
                'subject_attribute' => 'kc_user_id',
            ], null);
            $this->mapping($org, $provider, 'form-reviewers', SystemRole::Reviewer);
        });
    }

    private function verifiedDomain(string $domain, User $actor): void
    {
        SsoDomain::query()->create(['domain' => $domain, 'created_by' => $actor->id])->forceFill([
            'verification_token' => self::DOMAIN_TOKENS[$domain],
            'verified_at' => now(),
            'last_checked_at' => now(),
            'last_check_result' => DomainVerification::Verified,
        ])->save();
    }

    /** @param  array<string, mixed>  $config */
    private function provider(Organization $org, string $uuid, IdentityProviderType $type, string $name, array $config, ?string $secret): IdentityProvider
    {
        $provider = new IdentityProvider([
            'type' => $type,
            'name' => $name,
            'config' => $config,
            'jit_enabled' => true,
            'default_role_id' => $this->role($org, SystemRole::FieldWorker)->id,
        ]);
        $provider->forceFill([
            'uuid' => $uuid,
            'client_secret' => $secret,
            'status' => IdentityProviderStatus::Active,
            'last_success_at' => now(),
        ])->save();

        return $provider;
    }

    private function mapping(Organization $org, IdentityProvider $provider, string $group, SystemRole $role): void
    {
        IdentityProviderMapping::query()->create([
            'identity_provider_id' => $provider->id,
            'claim' => 'groups',
            'value' => $group,
            'role_id' => $this->role($org, $role)->id,
        ]);
    }

    private function role(Organization $org, SystemRole $role): Role
    {
        return Role::query()->where('organization_id', $org->id)->where('name', $role->value)->sole();
    }

    private function user(string $email, string $name): User
    {
        return User::query()->firstOrCreate(['email' => $email], [
            'name' => $name,
            'password' => DemoSeeder::PASSWORD,
            'email_verified_at' => now(),
        ]);
    }
}
