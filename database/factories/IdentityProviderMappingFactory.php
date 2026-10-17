<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A `groups` claim value mapped to a new group. Reuses the tenant's provider (one per organization).
 *
 * @extends Factory<IdentityProviderMapping>
 */
class IdentityProviderMappingFactory extends Factory
{
    protected $model = IdentityProviderMapping::class;

    public function definition(): array
    {
        return [
            'identity_provider_id' => IdentityProviderFactory::tenantProviderId(...),
            'claim' => 'groups',
            'value' => fake()->unique()->slug(2),
            'role_id' => null,
            'group_id' => fn (): int => Group::factory()->create()->id,
        ];
    }

    public function toRole(Role $role): static
    {
        return $this->state(fn (): array => ['role_id' => $role->id, 'group_id' => null]);
    }
}
