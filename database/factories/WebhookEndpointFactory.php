<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Workflows\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'url' => 'https://hooks.example.com/'.Str::random(8),
            'secret' => Str::random(40),
            'active' => true,
        ];
    }
}
