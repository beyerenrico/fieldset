<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Models\WorkflowStepRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowStepRun>
 */
class WorkflowStepRunFactory extends Factory
{
    protected $model = WorkflowStepRun::class;

    public function definition(): array
    {
        return [
            'workflow_run_id' => WorkflowRun::factory(),
            'index' => 0,
            'type' => 'notify',
        ];
    }
}
