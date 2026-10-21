<?php

declare(strict_types=1);

namespace App\Domain\Forms\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Enums\FormStatus;
use App\Domain\Forms\Events\FormPublished;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Forms\Support\DefinitionChecker;
use App\Domain\Forms\Support\DraftChangedException;
use App\Domain\Forms\Support\InvalidDefinitionException;
use App\Domain\Identity\Models\User;
use Fieldset\Export\DataSchemaExporter;
use Fieldset\Json\Json;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * Publish = meta-schema check, lint, then an immutable snapshot as version N+1 with checksum,
 * data contract and changelog (BUILD_PROMPT §7.6). Invalid definitions can never be published.
 * The draft is read under the row lock, so a save that landed after the caller's check is never
 * reverted: with `$expectedChecksum` a changed draft is refused, and publishing the draft never
 * writes it back (a rollback replaces it). FormPublished fires after commit (`form.published`).
 */
final readonly class PublishForm
{
    public function __construct(
        private DefinitionChecker $checker,
        private DataSchemaExporter $exporter,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  stdClass|null  $definition  a rollback's content; null publishes the stored draft
     * @param  string|null  $expectedChecksum  Json::checksum of the draft the caller checked
     *
     * @throws DraftChangedException when the stored draft differs from $expectedChecksum
     */
    public function handle(Form $form, string $changelog, User $actor, ?stdClass $definition = null, string $event = 'form.published', ?string $expectedChecksum = null): FormVersion
    {
        $changelog = trim($changelog);
        if ($changelog === '') {
            throw new InvalidArgumentException(__('forms.errors.changelog_required'));
        }
        $rollback = $definition instanceof stdClass ? Json::decode(Json::encode($definition)) : null;

        return DB::transaction(function () use ($form, $rollback, $changelog, $actor, $event, $expectedChecksum): FormVersion {
            // Serialize concurrent publishes and saves of the same form; the draft is read under the lock.
            $locked = Form::query()->whereKey($form->getKey())->lockForUpdate()->firstOrFail();
            $form->setRawAttributes($locked->getAttributes()); // clears cached casts before syncOriginal()
            $form->syncOriginal();
            $stored = Json::checksum($form->draft_definition);
            if ($expectedChecksum !== null && ! hash_equals($stored, $expectedChecksum)) {
                throw new DraftChangedException($expectedChecksum, $stored);
            }
            $definition = $rollback ?? Json::decode(Json::encode($form->draft_definition));

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
                'updated_by' => $actor->id,
                ...($rollback === null ? [] : ['draft_definition' => $rollback]),
            ])->save();
            SaveDraft::forgetAuditBaseline($form); // a new audited checkpoint

            $this->audit->record($event, $version, [
                'form_id' => $form->id, 'version' => $version->version, 'checksum' => $version->checksum,
            ], $actor);

            $published = new FormPublished($form->id, $version->id, $form->organization_id, $actor->id);
            DB::afterCommit(static fn () => event($published));

            return $version;
        });
    }
}
