<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Documents\Models\DesignVersion;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Rendition>
 */
class RenditionFactory extends Factory
{
    protected $model = Rendition::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'submission_uuid' => fn (): string => Submission::factory()->create()->uuid,
            'form_version_id' => fn (array $attrs): int => Submission::query()->whereKey($attrs['submission_uuid'])->firstOrFail()->form_version_id,
            'design_version_id' => fn (): int => DesignVersion::factory()->create()->id,
            'input_hash' => str_repeat('a', 64),
            'file_sha256' => str_repeat('b', 64),
            'renderer_version' => '1.0.0',
            'page_count' => 1,
            'disk' => 'local',
            'path' => 'renditions/x.pdf',
            'size' => 100,
            'reason' => Rendition::REASON_SUBMITTED,
        ];
    }
}
