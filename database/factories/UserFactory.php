<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UserStatus::Deactivated,
            'deactivated_at' => now(),
        ]);
    }

    /** Adds the user to an organization with the given roles (roles must already be seeded). */
    public function memberOf(Organization $organization, SystemRole ...$roles): static
    {
        return $this->afterCreating(function (User $user) use ($organization, $roles): void {
            app(AddMember::class)->handle($organization, $user, $roles === [] ? [SystemRole::FieldWorker] : array_values($roles));
        });
    }
}
