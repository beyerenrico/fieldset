<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Support\BlankDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    protected $model = Form::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(3, true));
        $key = BlankDefinition::keyFromName($name).'_'.fake()->unique()->numberBetween(1, 99999);

        return [
            'key' => $key,
            'name' => $name,
            'draft_definition' => BlankDefinition::make($key, $name, 'de-DE'),
        ];
    }
}
