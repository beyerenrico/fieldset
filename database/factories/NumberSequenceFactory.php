<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Forms\Models\Form;
use App\Domain\Submissions\Models\NumberSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NumberSequence>
 */
class NumberSequenceFactory extends Factory
{
    protected $model = NumberSequence::class;

    public function definition(): array
    {
        return ['form_id' => Form::factory(), 'period' => (string) fake()->unique()->numberBetween(2000, 9999), 'next_value' => 1];
    }
}
