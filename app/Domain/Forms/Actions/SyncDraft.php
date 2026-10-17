<?php

declare(strict_types=1);

namespace App\Domain\Forms\Actions;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Support\DefinitionChecker;
use App\Domain\Forms\Support\DefinitionReport;
use App\Domain\Forms\Support\InvalidDefinitionException;
use App\Domain\Identity\Models\User;
use FormSchema\Json\Json;
use Illuminate\Support\Facades\DB;
use JsonException;
use stdClass;

/**
 * Stores a whole definition sent by a builder, guarded by the checksum of the draft the builder last saw
 * (ADR 0037). A different stored draft is a `conflict` unless the caller forces the write; structurally
 * invalid definitions are refused (`invalid`) and stay in the builder; lint findings are stored with the
 * draft (they block publishing) and returned. Explicit saves are audited, autosaves are not (SaveDraft).
 *
 * @phpstan-type SyncResult array{status: 'saved'|'unchanged'|'invalid'|'conflict', checksum: string, lint: array{schema_errors: list<array{path: string, message: string}>, findings: list<array{severity: string, code: string, path: string}>}, savedAt: ?string}
 */
final readonly class SyncDraft
{
    public function __construct(private SaveDraft $saveDraft, private DefinitionChecker $checker) {}

    /**
     * @param  string|stdClass  $definition  the definition (JSON text from the browser, or a tree)
     * @param  string|null  $baseChecksum  Json::checksum of the draft the caller started from
     * @param  bool  $audit  an explicit save (audited) instead of an autosave
     * @return SyncResult
     */
    public function handle(Form $form, string|stdClass $definition, ?string $baseChecksum, User $actor, bool $force = false, bool $audit = false): array
    {
        $tree = is_string($definition) ? $this->decode($definition) : Json::decode(Json::encode($definition));
        if (! $tree instanceof stdClass) {
            $message = (string) __('forms.canvas.invalid_json');

            return $this->result('invalid', Json::checksum($form->draft_definition), new DefinitionReport([['path' => '', 'message' => $message]], []), null);
        }

        return DB::transaction(function () use ($form, $tree, $baseChecksum, $actor, $force, $audit): array {
            $this->lockAndRefresh($form);
            $stored = Json::checksum($form->draft_definition);

            if (Json::canonical($tree) === Json::canonical($form->draft_definition)) {
                return $this->result('unchanged', $stored, $this->checker->check($tree, $form), $form);
            }
            if (! $force && $baseChecksum !== $stored) {
                return $this->result('conflict', $stored, new DefinitionReport([], []), null);
            }

            try {
                $report = $this->saveDraft->handle($form, $tree, $actor, autosave: ! $audit);
            } catch (InvalidDefinitionException $e) {
                return $this->result('invalid', $stored, $e->report, null);
            }

            return $this->result('saved', Json::checksum($form->draft_definition), $report, $form);
        });
    }

    /** Serializes concurrent saves of one form and loads the stored draft into the caller's model. */
    private function lockAndRefresh(Form $form): void
    {
        $locked = Form::query()->whereKey($form->getKey())->lockForUpdate()->firstOrFail();
        $form->setRawAttributes($locked->getAttributes(), sync: true);
    }

    private function decode(string $json): mixed
    {
        if (strlen($json) > ImportDefinition::MAX_BYTES) {
            return null;
        }
        try {
            return Json::decode($json);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @param  'saved'|'unchanged'|'invalid'|'conflict'  $status
     * @return SyncResult
     */
    private function result(string $status, string $checksum, DefinitionReport $report, ?Form $saved): array
    {
        return [
            'status' => $status,
            'checksum' => $checksum,
            'lint' => $report->toArray(),
            'savedAt' => $saved?->updated_at?->toIso8601String(),
        ];
    }
}
