<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Submissions\Models\Attachment;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'submission_uuid' => fn (): string => Submission::factory()->create()->uuid,
            'kind' => 'photo', 'element_key' => 'photos', 'mime' => 'image/jpeg', 'size' => 10,
            'sha256' => str_repeat('a', 64), 'disk' => 'local', 'path' => 'attachments/x.jpg',
        ];
    }
}
