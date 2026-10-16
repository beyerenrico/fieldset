<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkflowRun>
 */
class WorkflowRunFactory extends Factory
{
    protected $model = WorkflowRun::class;

    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'trigger' => 'manual',
            'event_id' => (string) Str::uuid7(),
            'definition_snapshot' => ['name' => 'Test', 'trigger_type' => 'manual', 'trigger_config' => [], 'conditions' => null, 'steps' => []],
        ];
    }
}
