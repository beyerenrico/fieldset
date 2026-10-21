<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Enums\OidcFlavor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Generic OIDC provider in draft by default. One provider per organization: create it inside the tenant.
 *
 * @extends Factory<IdentityProvider>
 */
class IdentityProviderFactory extends Factory
{
    protected $model = IdentityProvider::class;

    public function definition(): array
    {
        return [
            'type' => IdentityProviderType::Oidc,
            'name' => 'Company SSO',
            'config' => [
                'flavor' => OidcFlavor::Generic->value,
                'issuer' => 'https://idp.example.test/realms/acme',
                'client_id' => 'fieldset',
                'scopes' => ['openid', 'email', 'profile'],
            ],
            'client_secret' => Str::random(40),
            'jit_enabled' => true,
        ];
    }

    /** The current tenant's provider (one per organization), created when it has none yet. */
    public static function tenantProviderId(): int
    {
        $id = IdentityProvider::query()->value('id');

        return $id === null ? IdentityProvider::factory()->create()->id : (int) $id;
    }

    public function saml(): static
    {
        return $this->state(fn (): array => [
            'type' => IdentityProviderType::Saml,
            'config' => [
                'metadata_url' => 'https://idp.example.test/realms/acme/protocol/saml/descriptor',
                'email_attribute' => 'email',
                'groups_attribute' => 'groups',
            ],
            'client_secret' => null,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => IdentityProviderStatus::Active, 'last_success_at' => now()]);
    }

    public function enforcing(User $breakGlass): static
    {
        return $this->active()->state(fn (): array => ['enforce_sso' => true, 'break_glass_user_id' => $breakGlass->id]);
    }
}
