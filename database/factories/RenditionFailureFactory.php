<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Documents\Models\RenditionFailure;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RenditionFailure>
 */
class RenditionFailureFactory extends Factory
{
    protected $model = RenditionFailure::class;

    public function definition(): array
    {
        return [
            'submission_uuid' => fn (): string => Submission::factory()->create()->uuid,
            'reason' => RenditionFailure::RENDER_FAILED,
            'message' => 'Gotenberg answered 503.',
            'attempts' => 1,
            'failed_at' => now(),
        ];
    }
}
