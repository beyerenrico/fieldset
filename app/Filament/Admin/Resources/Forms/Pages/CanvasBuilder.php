<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Pages;

use App\Domain\Forms\Models\Form;
use App\Filament\Admin\Resources\Forms\FormResource;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use FormSchema\Json\Json;
use Livewire\Attributes\Json as JsonResponse;
use LogicException;

/**
 * Builder v2 (ADR 0037): palette, canvas and inspector over the same draft as the classic builder. The
 * browser holds the working copy of the definition; this page validates whole definitions (sync/save),
 * hosts the inspector schema and the publish / Preview PDF / export actions. Skeleton: the method
 * signatures are the contract with resources/builder-v2/src/contract.ts (M7-T11 fills them in).
 */
final class CanvasBuilder extends Page
{
    use InteractsWithRecord;

    protected static string $resource = FormResource::class;

    protected string $view = 'filament.forms.builder-v2.page';

    /** @var array<string, mixed> */
    public array $inspectorData = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(FormResource::canEdit($this->getRecord()), 403);
    }

    public function formRecord(): Form
    {
        $record = $this->getRecord();
        assert($record instanceof Form);

        return $record;
    }

    /**
     * Embedded once at mount (`<script type="application/json" data-builder-config>`).
     *
     * @return array{formId: int, definition: mixed, checksum: string, lockedIds: list<string>, maxContainerDepth: int, palette: list<array<string, mixed>>, urls: array{preview: string, classic: string}}
     */
    public function builderConfig(): array
    {
        $form = $this->formRecord();

        return [
            'formId' => (int) $form->getKey(),
            'definition' => $form->draft_definition,
            'checksum' => Json::checksum($form->draft_definition),
            'lockedIds' => [],
            'maxContainerDepth' => 3,
            'palette' => [],
            'urls' => [
                'preview' => url('/form-preview/'),
                'classic' => FormResource::getUrl('edit', ['record' => $form]),
            ],
        ];
    }

    /**
     * Autosave of the whole definition (not audited).
     *
     * @return array{status: 'saved'|'unchanged'|'invalid'|'conflict', checksum: string, lint: array{schema_errors: list<array{path: string, message: string}>, findings: list<array{severity: string, code: string, path: string}>}, savedAt: ?string}
     */
    #[JsonResponse]
    public function sync(string $definition, string $baseChecksum, bool $force = false): array
    {
        throw new LogicException('CanvasBuilder::sync is not implemented yet (M7-T11).');
    }

    /**
     * Explicit save (audited); same result as sync().
     *
     * @return array{status: 'saved'|'unchanged'|'invalid'|'conflict', checksum: string, lint: array{schema_errors: list<array{path: string, message: string}>, findings: list<array{severity: string, code: string, path: string}>}, savedAt: ?string}
     */
    #[JsonResponse]
    public function save(string $definition, string $baseChecksum, bool $force = false): array
    {
        throw new LogicException('CanvasBuilder::save is not implemented yet (M7-T11).');
    }

    /** Loads a node (without children) into the inspector; $scope is the InspectorScope JSON. */
    public function inspect(string $kind, ?string $id, string $node, string $scope): void
    {
        // Skeleton: no-op until M7-T11 (the inspector stays empty).
    }

    /** Validates the inspector and dispatches `builder-v2:node-changed` {kind, id, node}. */
    public function commitInspector(string $scope): void
    {
        // Skeleton: no-op until M7-T11 (no node-changed event yet).
    }

    /** Clones a subtree: fresh ids, unique keys in the target scope, rewritten references. */
    #[JsonResponse]
    public function cloneNode(string $node, string $scopeKeys): string
    {
        throw new LogicException('CanvasBuilder::cloneNode is not implemented yet (M7-T11).');
    }

    public function inspector(Schema $schema): Schema
    {
        return $schema->statePath('inspectorData')->components([]);
    }

    public function getTitle(): string
    {
        return (string) __('forms.canvas.title', ['name' => $this->formRecord()->name]);
    }
}
