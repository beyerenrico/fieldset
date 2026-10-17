<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\Privacy\Models\DataExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataExport>
 */
class DataExportFactory extends Factory
{
    protected $model = DataExport::class;

    public function definition(): array
    {
        return [
            'requested_by' => User::factory(),
            'kind' => 'audit_log',
            'status' => 'pending',
            'options' => [],
            'disk' => 'local',
        ];
    }
}
