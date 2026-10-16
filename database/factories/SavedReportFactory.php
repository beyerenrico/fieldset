<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Forms\Models\Form;
use App\Domain\Reports\Models\SavedReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedReport>
 */
class SavedReportFactory extends Factory
{
    protected $model = SavedReport::class;

    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'name' => rtrim(fake()->sentence(3), '.'),
            'filters' => [],
            'metric' => 'count',
            'visibility' => 'organization',
            'schedule' => 'none',
            'recipients' => [],
        ];
    }
}
