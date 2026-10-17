<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Pages;

use App\Domain\Documents\Rendering\PreviewDocument;
use App\Domain\Documents\Rendering\RenderBudgetExceededException;
use App\Domain\Documents\Rendering\RenderFailedException;
use App\Domain\Forms\Actions\ExportDefinition;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\SyncDraft;
use App\Domain\Forms\Builder\ElementCloner;
use App\Domain\Forms\Builder\ElementFields;
use App\Domain\Forms\Builder\FieldCodec;
use App\Domain\Forms\Builder\NodeMapper;
use App\Domain\Forms\Builder\PaletteCatalog;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Support\DefinitionChecker;
use App\Domain\Forms\Support\InvalidDefinitionException;
use App\Domain\Forms\Support\PublishedElementIds;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Filament\Admin\Resources\Forms\FormResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use FormSchema\Definition\InvalidDefinitionException as InvalidSpecDefinition;
use FormSchema\Json\Json;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Livewire\Attributes\Json as JsonResponse;
use Livewire\Attributes\Locked;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builder v2 (ADR 0037): palette, canvas and inspector over the same draft as the classic builder. The
 * browser holds the working copy of the definition; this page validates whole definitions (sync/save),
 * hosts the inspector schema and the publish / Preview PDF / export actions. The method signatures are
 * the contract with resources/builder-v2/src/contract.ts; JSON travels as strings.
 *
 * @phpstan-import-type SyncResult from SyncDraft
 * @phpstan-import-type InspectorScope from CanvasInspectorSchema
 */
final class CanvasBuilder extends Page
{
    use InteractsWithRecord;

    public const string NODE_CHANGED = 'builder-v2:node-changed';

    public const string PUBLISHED = 'builder-v2:published';

    protected static string $resource = FormResource::class;

    protected string $view = 'filament.forms.builder-v2.page';

    /** Palette, canvas and inspector need the full width. */
    protected Width|string|null $maxContentWidth = Width::Full;

    /** @var array<string, mixed> */
    public array $inspectorData = [];

    #[Locked]
    public ?string $inspectorKind = null;

    #[Locked]
    public ?string $inspectorId = null;

    #[Locked]
    public ?string $inspectorType = null;

    #[Locked]
    public string $inspectorScope = '{}';

    /** The key the inspected palette node had or was given from its label; null once the key is edited. */
    #[Locked]
    public ?string $autoKeyFrom = null;

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
            'lockedIds' => PublishedElementIds::of($form),
            'maxContainerDepth' => 3,
            'palette' => PaletteCatalog::groups(),
            'urls' => [
                'preview' => url('/form-preview/'),
                'classic' => FormResource::getUrl('edit', ['record' => $form]),
            ],
        ];
    }

    /**
     * Autosave of the whole definition (not audited).
     *
     * @return SyncResult
     */
    #[JsonResponse]
    public function sync(string $definition, string $baseChecksum, bool $force = false): array
    {
        return $this->store($definition, $baseChecksum, $force, audit: false);
    }

    /**
     * Explicit save (audited); same result as sync().
     *
     * @return SyncResult
     */
    #[JsonResponse]
    public function save(string $definition, string $baseChecksum, bool $force = false): array
    {
        return $this->store($definition, $baseChecksum, $force, audit: true);
    }

    /** Loads a node (without children) into the inspector; $scope is the InspectorScope JSON. */
    public function inspect(string $kind, ?string $id, string $node, string $scope): void
    {
        $tree = $this->decodeObject($node);
        $knownType = $kind !== NodeMapper::ELEMENT || in_array($tree->type ?? null, ElementFields::ALL_TYPES, true);
        if (! in_array($kind, NodeMapper::KINDS, true) || ! $tree instanceof stdClass || ! $knownType) {
            throw ValidationException::withMessages(['inspector' => __('forms.canvas.invalid_json')]);
        }
        $own = NodeMapper::withoutChildren($kind, $tree);
        $parsed = CanvasInspectorSchema::scope($scope);

        $this->inspectorKind = $kind;
        $this->inspectorId = $kind === NodeMapper::SETTINGS ? null : $id;
        $this->inspectorType = $kind === NodeMapper::ELEMENT && is_string($own->type ?? null) ? $own->type : null;
        $this->inspectorScope = $scope;
        $this->autoKeyFrom = $kind === NodeMapper::ELEMENT && $parsed['autoKey'] && is_string($own->key ?? null) ? $own->key : null;

        $this->resetErrorBag();
        $this->cacheSchema('inspector');
        $this->getSchema('inspector')?->fill($this->nodeMapper($parsed)->toState($kind, $own));
    }

    /** Validates the inspector and dispatches `builder-v2:node-changed` {kind, id, node}. */
    public function commitInspector(string $scope): void
    {
        $schema = $this->getSchema('inspector');
        if ($this->inspectorKind === null || ! $schema instanceof Schema) {
            return;
        }
        $this->inspectorScope = $scope;
        $state = $schema->getState(); // field errors render on failure; nothing is dispatched
        $node = $this->nodeMapper(CanvasInspectorSchema::scope($scope))->fromState($this->inspectorKind, $state, $this->inspectorType);

        $this->dispatch(self::NODE_CHANGED, kind: $this->inspectorKind, id: $this->inspectorId, node: Json::encode($node));
    }

    /** Clones a subtree: fresh ids, unique keys in the target scope, rewritten references. */
    #[JsonResponse]
    public function cloneNode(string $node, string $scopeKeys): string
    {
        $tree = $this->decodeObject($node);
        $keys = $this->decode($scopeKeys);
        if (! $tree instanceof stdClass || ! is_array($keys)) {
            throw ValidationException::withMessages(['node' => __('forms.canvas.invalid_json')]);
        }

        return Json::encode(ElementCloner::clone($tree, array_values(array_filter($keys, is_string(...)))));
    }

    /**
     * Label → key for the inspected element (called by ElementBlocks): a blank key is always suggested;
     * a palette node (autoKey) keeps following its label until its key is edited.
     */
    public function suggestElementKey(string $label, string $currentKey): ?string
    {
        $following = $this->autoKeyFrom !== null && $currentKey === $this->autoKeyFrom;
        if ($currentKey !== '' && ! $following) {
            $this->autoKeyFrom = null;

            return null;
        }
        $taken = $this->scope()['scopeKeys'];
        $original = FieldCodec::decodeObject($this->inspectorData['_source'] ?? null)->key ?? null;
        foreach (array_unique(array_filter([$currentKey, $original], is_string(...))) as $ownKey) {
            $index = array_search($ownKey, $taken, true); // the node's own key is in its scope once
            if ($index !== false) {
                unset($taken[$index]);
            }
        }
        $key = CanvasInspectorSchema::uniqueKey($label, array_values($taken));
        if ($key === '') {
            return null;
        }
        if ($following) {
            $this->autoKeyFrom = $key;
        }

        return $key;
    }

    /** @return array<string, string> data element keys for logic and calculation selects */
    public function fieldKeyOptions(): array
    {
        $keys = array_keys($this->scope()['keyTypes']);
        sort($keys);

        return array_combine($keys, $keys);
    }

    /** @return array<string, string> */
    public function repeaterKeyOptions(): array
    {
        $keys = array_keys(array_filter($this->scope()['keyTypes'], fn (string $type): bool => $type === 'repeater'));

        return array_combine($keys, $keys);
    }

    /** @return array<string, string> keys of the elements inside a repeater */
    public function rowKeyOptions(string $repeater): array
    {
        $keys = $this->scope()['rowKeys'][$repeater] ?? [];

        return array_combine($keys, $keys);
    }

    public function inspector(Schema $schema): Schema
    {
        return CanvasInspectorSchema::configure($schema, $this->inspectorKind, $this->inspectorType);
    }

    public function publishAction(): Action
    {
        return Action::make('publish')->label(__('forms.actions.publish'))->icon('heroicon-o-rocket-launch')->color('success')
            ->authorize(fn (): bool => auth()->user()?->can('publish', $this->formRecord()) ?? false)
            ->schema([Textarea::make('changelog')->label(__('forms.versions.changelog'))->required()->rows(3)])
            ->mountUsing(function (Action $action, array $arguments, ?Schema $schema): void {
                $this->guardDraft($action, $arguments, lint: true);
                $schema?->fill();
            })
            ->action(function (Action $action, array $arguments, array $data): void {
                $this->guardDraft($action, $arguments, lint: false);
                try {
                    $version = app(PublishForm::class)->handle($this->formRecord(), (string) $data['changelog'], $this->actor());
                } catch (InvalidDefinitionException) {
                    Notification::make()->danger()->title(__('forms.errors.invalid_definition'))->send();

                    return;
                }
                Notification::make()->success()->title(__('forms.versions.published', ['version' => $version->version]))->send();
                $this->dispatch(self::PUBLISHED, version: $version->version, lockedIds: PublishedElementIds::of($this->formRecord()));
            });
    }

    /** "Preview PDF" of the stored draft (the client flushes its autosave first) with sample data. */
    public function previewPdfAction(): Action
    {
        return Action::make('previewPdf')->label(__('documents.actions.preview_pdf'))->icon('heroicon-o-document-arrow-down')->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('view', $this->formRecord()) ?? false)
            ->action(function (Action $action, array $arguments): ?StreamedResponse {
                $this->guardDraft($action, $arguments, lint: false);
                $form = $this->formRecord();
                try {
                    /** @var Organization $organization */
                    $organization = Filament::getTenant();
                    $pdf = app(PreviewDocument::class)->forDefinition($form->draft_definition, $organization, $this->actor())->pdf;
                } catch (InvalidArgumentException|InvalidSpecDefinition) {
                    Notification::make()->danger()->title(__('documents.errors.preview_invalid'))->send();

                    return null;
                } catch (RenderFailedException|RenderBudgetExceededException $e) {
                    Notification::make()->danger()->title(__('documents.errors.preview_failed'))->body($e->getMessage())->send();

                    return null;
                }

                return response()->streamDownload(fn (): int => print ($pdf), $form->key.'.preview.pdf', ['Content-Type' => 'application/pdf']);
            });
    }

    public function exportDraftAction(): Action
    {
        return Action::make('exportDraft')->label(__('forms.actions.export_json'))->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('view', $this->formRecord()) ?? false)
            ->action(function (Action $action, array $arguments): StreamedResponse {
                $this->guardDraft($action, $arguments, lint: false);
                $form = $this->formRecord();
                $json = app(ExportDefinition::class)->handle($form, null, $this->actor());

                return response()->streamDownload(fn (): int => print ($json), $form->key.'.draft.form.json', ['Content-Type' => 'application/json']);
            });
    }

    public function getTitle(): string
    {
        return (string) __('forms.canvas.title', ['name' => $this->formRecord()->name]);
    }

    /**
     * Actions run on the stored draft, so they refuse a checksum other than the stored one (the client
     * then saves or reloads first); publishing also refuses a draft with lint errors.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function guardDraft(Action $action, array $arguments, bool $lint): void
    {
        $form = $this->formRecord()->refresh();
        $checksum = is_string($arguments['checksum'] ?? null) ? $arguments['checksum'] : '';
        if (! hash_equals(Json::checksum($form->draft_definition), $checksum)) {
            Notification::make()->danger()->title(__('forms.canvas.stale_title'))->body(__('forms.canvas.stale_action'))->send();
            $action->cancel();
        }
        if ($lint && app(DefinitionChecker::class)->check($form->draft_definition, $form)->hasErrors()) {
            Notification::make()->danger()->title(__('forms.errors.invalid_definition'))->send();
            $action->cancel();
        }
    }

    /** @return SyncResult */
    private function store(string $definition, string $baseChecksum, bool $force, bool $audit): array
    {
        abort_unless(auth()->user()?->can('update', $this->formRecord()) ?? false, 403);

        return app(SyncDraft::class)->handle($this->formRecord(), $definition, $baseChecksum, $this->actor(), $force, $audit);
    }

    /** @param  InspectorScope  $scope */
    private function nodeMapper(array $scope): NodeMapper
    {
        return new NodeMapper($scope['keyTypes'], PublishedElementIds::of($this->formRecord()));
    }

    /** @return InspectorScope */
    private function scope(): array
    {
        return CanvasInspectorSchema::scope($this->inspectorScope);
    }

    private function decodeObject(string $json): ?stdClass
    {
        $decoded = $this->decode($json);

        return $decoded instanceof stdClass ? $decoded : null;
    }

    private function decode(string $json): mixed
    {
        try {
            return Json::decode($json);
        } catch (JsonException) {
            return null;
        }
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
