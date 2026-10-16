<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Models\SubmissionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionEvent>
 */
class SubmissionEventFactory extends Factory
{
    protected $model = SubmissionEvent::class;

    public function definition(): array
    {
        return ['submission_uuid' => fn (): string => Submission::factory()->create()->uuid, 'to_status' => 'draft'];
    }
}
