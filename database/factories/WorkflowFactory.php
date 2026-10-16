<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
{
    protected $model = Workflow::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'trigger_type' => TriggerType::SubmissionSubmitted,
            'trigger_config' => [],
            'steps' => [],
            'active' => true,
        ];
    }

    /** @param  list<array{type: string, data: array<string, mixed>}>  $steps */
    public function withSteps(array $steps): static
    {
        return $this->state(fn (): array => ['steps' => $steps]);
    }

    /** @param  array<string, mixed>  $config */
    public function trigger(TriggerType $type, array $config = []): static
    {
        return $this->state(fn (): array => ['trigger_type' => $type, 'trigger_config' => $config]);
    }
}
