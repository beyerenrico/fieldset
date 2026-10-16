<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'submission_uuid' => fn (): string => Submission::factory()->create()->uuid,
            'due_at' => null,
        ];
    }
}
