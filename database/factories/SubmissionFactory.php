<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'form_version_id' => FormVersion::factory(),
            'form_id' => fn (array $attrs): int => FormVersion::query()->whereKey($attrs['form_version_id'])->firstOrFail()->form_id,
            'data' => (object) [],
        ];
    }

    public function forForm(Form $form): static
    {
        return $this->state(fn (): array => ['form_id' => $form->id, 'form_version_id' => $form->current_version_id]);
    }
}
