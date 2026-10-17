<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A linked account at the tenant's provider (reused, one per organization).
 *
 * @extends Factory<ExternalIdentity>
 */
class ExternalIdentityFactory extends Factory
{
    protected $model = ExternalIdentity::class;

    public function definition(): array
    {
        return [
            'identity_provider_id' => IdentityProviderFactory::tenantProviderId(...),
            'user_id' => fn (): int => User::factory()->create()->id,
            'subject' => (string) Str::uuid(),
            'issuer' => 'https://idp.example.test/realms/acme',
            'last_email' => null,
        ];
    }
}
