<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Dashboards\Models\DashboardPin;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Models\SavedReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardPin>
 */
class DashboardPinFactory extends Factory
{
    protected $model = DashboardPin::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'saved_report_id' => SavedReport::factory(),
            'position' => 1,
        ];
    }
}
