<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Forms\Models\Form;
use App\Domain\Privacy\Models\LegalHold;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalHold>
 */
class LegalHoldFactory extends Factory
{
    protected $model = LegalHold::class;

    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'reason' => 'Statutory retention (invoices)',
        ];
    }
}
