<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Models\IdempotencyKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdempotencyKey>
 */
class IdempotencyKeyFactory extends Factory
{
    protected $model = IdempotencyKey::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'key' => (string) Str::uuid(), 'request_hash' => str_repeat('0', 64), 'status_code' => 200, 'response' => '{}'];
    }
}
