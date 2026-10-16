<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormAccess;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormAccess>
 */
class FormAccessFactory extends Factory
{
    protected $model = FormAccess::class;

    public function definition(): array
    {
        return ['form_id' => Form::factory(), 'role_name' => 'field_worker'];
    }
}
