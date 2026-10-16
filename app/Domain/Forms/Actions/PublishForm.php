<?php

declare(strict_types=1);

namespace App\Domain\Forms\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Enums\FormStatus;
use App\Domain\Forms\Events\FormPublished;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Forms\Support\DefinitionChecker;
use App\Domain\Forms\Support\InvalidDefinitionException;
use App\Domain\Identity\Models\User;
use FormSchema\Export\DataSchemaExporter;
use FormSchema\Json\Json;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * Publish = meta-schema check, lint, then an immutable snapshot as version N+1 with checksum,
 * data contract and changelog (BUILD_PROMPT §7.6). Invalid definitions can never be published.
 * FormPublished fires after commit (workflow trigger `form.published`).
 */
final readonly class PublishForm
{
    public function __construct(
        private DefinitionChecker $checker,
        private DataSchemaExporter $exporter,
        private AuditLogger $audit,
    ) {}

    public function handle(Form $form, string $changelog, User $actor, ?stdClass $definition = null, string $event = 'form.published'): FormVersion
    {
        $changelog = trim($changelog);
        if ($changelog === '') {
            throw new InvalidArgumentException(__('forms.errors.changelog_required'));
        }

        $definition = Json::decode(Json::encode($definition ?? $form->draft_definition));

        return DB::transaction(function () use ($form, $definition, $changelog, $actor, $event): FormVersion {
            // Serialize concurrent publishes of the same form.
            Form::query()->whereKey($form->id)->lockForUpdate()->first();

            $report = $this->checker->check($definition, $form);
            if ($report->hasErrors()) {
                throw new InvalidDefinitionException($report);
            }

            $version = FormVersion::query()->create([
                'form_id' => $form->id,
                'version' => $form->latestVersionNumber() + 1,
                'definition' => $definition,
                'data_json_schema' => $this->exporter->export($definition),
                'checksum' => Json::checksum($definition),
                'changelog' => $changelog,
                'published_by' => $actor->id,
                'published_at' => now(),
            ]);

            $form->forceFill([
                'current_version_id' => $version->id,
                'status' => FormStatus::Active,
                'draft_definition' => $definition,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record($event, $version, [
                'form_id' => $form->id, 'version' => $version->version, 'checksum' => $version->checksum,
            ], $actor);

            $published = new FormPublished($form->id, $version->id, $form->organization_id, $actor->id);
            DB::afterCommit(static fn () => event($published));

            return $version;
        });
    }
}
