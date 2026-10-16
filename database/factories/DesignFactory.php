<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Documents\Designs\DesignSettings;
use App\Domain\Documents\Models\Design;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Design>
 */
class DesignFactory extends Factory
{
    protected $model = Design::class;

    public function definition(): array
    {
        return [
            'key' => 'design_'.fake()->unique()->numberBetween(1, 999999),
            'name' => rtrim(fake()->sentence(2), '.'),
            'settings' => DesignSettings::defaults()->toArray(),
        ];
    }
}
