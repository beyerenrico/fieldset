<?php

declare(strict_types=1);

namespace App\Domain\Forms\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Schema\DefinitionDiff;
use App\Domain\Forms\Schema\DiffEntry;
use App\Domain\Forms\Support\DefinitionChecker;
use App\Domain\Forms\Support\DefinitionReport;
use App\Domain\Forms\Support\InvalidDefinitionException;
use App\Domain\Identity\Models\User;
use FormSchema\Json\Json;
use stdClass;

/**
 * Stores the mutable working copy. Structurally invalid definitions are refused; lint errors are
 * allowed in drafts (they block publishing). Autosaves are not audited, explicit saves are.
 */
final readonly class SaveDraft
{
    public function __construct(private DefinitionChecker $checker, private AuditLogger $audit) {}

    /**
     * @param  bool  $autosave  autosaves and saves that are part of another audited action are not audited
     */
    public function handle(Form $form, stdClass $definition, User $actor, bool $autosave = false): DefinitionReport
    {
        $definition = Json::decode(Json::encode($definition));
        // Compare with the stored JSON, not the (possibly mutated) cached cast value.
        $raw = $form->getRawOriginal('draft_definition');
        $before = is_string($raw) ? Json::decode($raw) : $form->draft_definition;
        // The form key stays authoritative once a version exists (keys are joined in reports).
        if ($form->current_version_id !== null) {
            $definition->key = $form->key;
        }
        $title = is_string($definition->title ?? null) && $definition->title !== '' ? $definition->title : $form->name;

        $report = $this->checker->check($definition, $form);
        if (! $report->isStructurallyValid()) {
            throw new InvalidDefinitionException($report);
        }

        $form->forceFill([
            'draft_definition' => $definition,
            'name' => $title,
            'key' => is_string($definition->key) ? $definition->key : $form->key,
            'updated_by' => $actor->id,
        ])->save();

        if (! $autosave && Json::checksum($before) !== Json::checksum($definition)) {
            $this->audit->record('form.updated', $form, [
                'before' => ['checksum' => Json::checksum($before)],
                'after' => ['checksum' => Json::checksum($definition)],
                'changes' => array_map(static fn (DiffEntry $e): array => ['change' => $e->change, 'key' => $e->key, 'properties' => $e->properties],
                    DefinitionDiff::compare($before, $definition)),
                'lint_errors' => count($report->errors()),
            ], $actor);
        }

        return $report;
    }
}
