<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Sso\Enums\DomainVerification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An unverified domain claim of the current tenant.
 *
 * @extends Factory<SsoDomain>
 */
class SsoDomainFactory extends Factory
{
    protected $model = SsoDomain::class;

    public function definition(): array
    {
        return [
            'domain' => Str::lower(fake()->unique()->domainName()),
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'verified_at' => now(),
            'last_checked_at' => now(),
            'last_check_result' => DomainVerification::Verified,
        ]);
    }
}
