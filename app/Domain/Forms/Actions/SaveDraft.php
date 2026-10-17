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
use Illuminate\Support\Facades\Cache;
use JsonException;
use stdClass;

/**
 * Stores the mutable working copy. Structurally invalid definitions are refused; lint errors are
 * allowed in drafts (they block publishing). Autosaves are not audited, explicit saves are: they are
 * diffed against the last audited state, which the first autosave after an audit keeps in the cache
 * (no column; publishing is a new checkpoint and forgets it). Without that baseline (cache lost) the
 * explicit save is diffed against the stored draft it replaces.
 */
final readonly class SaveDraft
{
    private const int BASELINE_TTL_DAYS = 30;

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

        if ($autosave) {
            $this->keepBaseline($form, $before, $definition);
        } else {
            $this->recordUpdate($form, $this->pullBaseline($form) ?? $before, $definition, $report, $actor);
        }

        return $report;
    }

    /**
     * An explicit save whose definition an autosave already stored: audits the changes since the last
     * audited state (no entry when there is none).
     */
    public function auditPending(Form $form, User $actor, DefinitionReport $report): void
    {
        $baseline = $this->pullBaseline($form);
        if ($baseline instanceof stdClass) {
            $this->recordUpdate($form, $baseline, $form->draft_definition, $report, $actor);
        }
    }

    /** Publishing (or a rollback) is a new audited checkpoint: later explicit saves diff against it. */
    public static function forgetAuditBaseline(Form $form): void
    {
        Cache::forget(self::baselineKey($form));
    }

    private static function baselineKey(Form $form): string
    {
        return 'forms:'.$form->getKey().':audit-baseline';
    }

    /** The first autosave after an audit keeps the audited state (Cache::add never replaces it). */
    private function keepBaseline(Form $form, stdClass $before, stdClass $after): void
    {
        if (Json::checksum($before) !== Json::checksum($after)) {
            Cache::add(self::baselineKey($form), Json::encode($before), now()->addDays(self::BASELINE_TTL_DAYS));
        }
    }

    private function pullBaseline(Form $form): ?stdClass
    {
        $json = Cache::pull(self::baselineKey($form));
        if (! is_string($json)) {
            return null;
        }
        try {
            $baseline = Json::decode($json);
        } catch (JsonException) {
            return null;
        }

        return $baseline instanceof stdClass ? $baseline : null;
    }

    private function recordUpdate(Form $form, stdClass $before, stdClass $after, DefinitionReport $report, User $actor): void
    {
        if (Json::checksum($before) === Json::checksum($after)) {
            return;
        }
        $this->audit->record('form.updated', $form, [
            'before' => ['checksum' => Json::checksum($before)],
            'after' => ['checksum' => Json::checksum($after)],
            'changes' => array_map(static fn (DiffEntry $e): array => ['change' => $e->change, 'key' => $e->key, 'properties' => $e->properties],
                DefinitionDiff::compare($before, $after)),
            'lint_errors' => count($report->errors()),
        ], $actor);
    }
}
