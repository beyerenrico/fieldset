<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Forms\Models\Form;
use App\Domain\Privacy\Models\RetentionPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RetentionPolicy>
 */
class RetentionPolicyFactory extends Factory
{
    protected $model = RetentionPolicy::class;

    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'months' => 24,
            'action' => 'anonymize',
        ];
    }
}
