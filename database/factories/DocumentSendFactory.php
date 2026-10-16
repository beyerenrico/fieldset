<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Mail\Enums\DocumentSendStatus;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentSend>
 */
class DocumentSendFactory extends Factory
{
    protected $model = DocumentSend::class;

    public function definition(): array
    {
        return [
            'submission_uuid' => fn (): string => Submission::factory()->create()->uuid,
            'recipient_email' => fake()->safeEmail(),
            'recipient_name' => fake()->name(),
            'status' => DocumentSendStatus::WaitingForDocument,
        ];
    }
}
