<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use FormSchema\Json\Json;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormVersion>
 */
class FormVersionFactory extends Factory
{
    protected $model = FormVersion::class;

    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'version' => 1,
            'definition' => fn (array $attrs): mixed => Form::query()->findOrFail($attrs['form_id'])->draft_definition,
            'data_json_schema' => (object) ['type' => 'object'],
            'checksum' => fn (array $attrs): string => Json::checksum(Form::query()->findOrFail($attrs['form_id'])->draft_definition),
            'changelog' => 'Initial version',
            'published_at' => now(),
        ];
    }
}
