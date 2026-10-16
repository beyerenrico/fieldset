<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Exports\RunExport;
use App\Domain\Reports\Models\ReportExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportExport>
 */
class ReportExportFactory extends Factory
{
    protected $model = ReportExport::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'form_id' => Form::factory(),
            'format' => 'xlsx',
            'status' => 'pending',
            'file_disk' => 'local',
            'exporter' => RunExport::class,
            'total_rows' => 0,
            'options' => [],
        ];
    }
}
