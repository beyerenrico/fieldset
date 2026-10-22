<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Pages;

use App\Domain\Documents\Rendering\PreviewDocument;
use App\Domain\Documents\Rendering\RenderBudgetExceededException;
use App\Domain\Documents\Rendering\RenderFailedException;
use App\Domain\Forms\Actions\ExportDefinition;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\SyncDraft;
use App\Domain\Forms\Actions\SyncFormAccess;
use App\Domain\Forms\Builder\BuilderMapper;
use App\Domain\Forms\Builder\KeyTypes;
use App\Domain\Forms\Import\DismissImportReport;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormAccess;
use App\Domain\Forms\Schema\DefinitionTools;
use App\Domain\Forms\Support\DefinitionChecker;
use App\Domain\Forms\Support\DefinitionReport;
use App\Domain\Forms\Support\DraftChangedException;
use App\Domain\Forms\Support\InvalidDefinitionException;
use App\Domain\Forms\Support\PublishedElementIds;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Filament\Admin\Resources\Forms\FormResource;
use App\Filament\Admin\Resources\Forms\RelationManagers\VersionsRelationManager;
use Fieldset\Definition\InvalidDefinitionException as InvalidSpecDefinition;
use Fieldset\Json\Json;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builder v1 page: edits the draft through the Filament Builder, lints after every save, autosaves
 * and publishes (BUILD_PROMPT §8.2, §8.10). Every save is guarded by the checksum of the draft this
 * page loaded, so a stale tab never overwrites what the canvas builder saved meanwhile (ADR 0037).
 *
 * @property Form $record
 */
class EditForm extends EditRecord
{
    protected static string $resource = FormResource::class;

    /** @var array{schema_errors: list<array{path: string, message: string}>, findings: list<array{severity: string, code: string, path: string}>} */
    public array $lint = ['schema_errors' => [], 'findings' => []];

    public ?string $lastSavedHash = null;

    /** Checksum of the stored draft this page last loaded or saved. */
    public ?string $loadedChecksum = null;

    public bool $staleNotified = false;

    /** Livewire calls this before mount and before every update: one classic render at a time (ClassicBuilderSlot). */
    public function boot(ClassicBuilderSlot $slot): void
    {
        $slot->hold();
    }

    public function form(Schema $schema): Schema
    {
        return FormEditorSchema::configure($schema);
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->loadedChecksum = Json::checksum($this->record->draft_definition);
        $this->staleNotified = false;
        $this->refreshLint($this->record->draft_definition);
        $state = $this->mapper()->toState($this->record->draft_definition);
        $grants = FormAccess::query()->where('form_id', $this->record->id)->get();
        $state['access_groups'] = $grants->whereNotNull('group_id')->pluck('group_id')->map(fn ($id): int => (int) $id)->values()->all();
        $state['access_roles'] = $grants->whereNotNull('role_name')->pluck('role_name')->values()->all();

        return $state;
    }

    /** Remember the hydrated state so autosave only writes real changes. */
    protected function afterFill(): void
    {
        $raw = $this->form->getRawState();
        $this->lastSavedHash = md5(serialize(is_array($raw) ? $raw : $raw->toArray()));
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Form $record */
        try {
            $saved = $this->persist($record, $data, autosave: false);
        } catch (InvalidDefinitionException|InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt($e->getMessage(), $e->getCode(), $e);
        }
        if (! $saved) {
            throw new Halt;
        }

        return $record;
    }

    /** Called by the polling element in the editor schema. Never throws; never audits. */
    public function autosave(): void
    {
        $raw = $this->form->getRawState();
        /** @var array<string, mixed> $state */
        $state = is_array($raw) ? $raw : $raw->toArray();
        $hash = md5(serialize($state));
        if ($this->staleNotified || $hash === $this->lastSavedHash || ! (auth()->user()?->can('update', $this->record) ?? false)) {
            return;
        }

        try {
            if ($this->persist($this->record, $state, autosave: true, background: true)) {
                $this->lastSavedHash = $hash;
            }
        } catch (InvalidArgumentException|InvalidDefinitionException) {
            // Incomplete edits stay in the browser until they are valid enough to store.
        }
    }

    /**
     * Stores the builder state unless the draft changed since this page loaded it (then it notifies
     * and returns false).
     *
     * @param  array<string, mixed>  $data
     * @param  bool  $autosave  not audited (autosaves, and the save that is part of publishing)
     * @param  bool  $background  the polling autosave: its stale notice is shown once per page load
     */
    private function persist(Form $record, array $data, bool $autosave, bool $background = false): bool
    {
        $definition = $this->mapper()->toDefinition($data);
        $result = app(SyncDraft::class)->handle($record, $definition, $this->loadedChecksum, $this->actor(), audit: ! $autosave);
        if ($result['status'] === 'conflict') {
            $this->notifyStale($background);

            return false;
        }
        $this->lint = $result['lint'];
        if ($result['status'] === 'invalid') {
            throw new InvalidDefinitionException(new DefinitionReport($result['lint']['schema_errors'], []));
        }
        $this->loadedChecksum = $result['checksum'];

        if (! $autosave) {
            app(SyncFormAccess::class)->handle(
                $record,
                array_values(array_map(intval(...), is_array($data['access_groups'] ?? null) ? $data['access_groups'] : [])),
                array_values(array_map(strval(...), is_array($data['access_roles'] ?? null) ? $data['access_roles'] : [])),
                $this->actor(),
            );
        }

        return true;
    }

    /**
     * The draft was saved elsewhere (another tab or the canvas builder). The polling autosave says so once
     * per page load (and stops trying); an explicit save, publish or the Canvas action always says so.
     */
    private function notifyStale(bool $background = false): void
    {
        if ($background && $this->staleNotified) {
            return;
        }
        $this->staleNotified = true;
        Notification::make()->danger()->persistent()
            ->title(__('forms.canvas.stale_title'))->body(__('forms.canvas.stale_body'))
            ->actions([Action::make('reload')->label(__('forms.canvas.reload'))->button()
                ->url(FormResource::getUrl('edit', ['record' => $this->record]))])
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [$this->publishAction(), $this->previewPdfAction(), $this->exportDraftAction(), $this->canvasAction(), $this->dismissImportReportAction()];
    }

    /** Builder v2 (ADR 0037): saves the classic builder first, then opens the canvas builder on the same draft. */
    private function canvasAction(): Action
    {
        return Action::make('canvas')->label(__('forms.canvas.open'))->icon('heroicon-o-squares-2x2')->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('update', $this->record) ?? false)
            ->action(function (): void {
                $state = $this->form->getState();
                try {
                    $saved = $this->persist($this->record, is_array($state) ? $state : [], autosave: false);
                } catch (InvalidDefinitionException|InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }
                if ($saved) {
                    $this->redirect(FormResource::getUrl('canvas', ['record' => $this->record]));
                }
            });
    }

    /** "Preview PDF" (§8.2): the unsaved definition with sample data and the design it selects. */
    private function previewPdfAction(): Action
    {
        return Action::make('previewPdf')->label(__('documents.actions.preview_pdf'))->icon('heroicon-o-document-arrow-down')->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('view', $this->record) ?? false)
            ->action(function (): ?StreamedResponse {
                try {
                    $definition = $this->mapper()->toDefinition(is_array($this->data) ? $this->data : []);
                    /** @var Organization $organization */
                    $organization = Filament::getTenant();
                    $pdf = app(PreviewDocument::class)->forDefinition($definition, $organization, $this->actor())->pdf;
                } catch (InvalidArgumentException|InvalidSpecDefinition) {
                    Notification::make()->danger()->title(__('documents.errors.preview_invalid'))->send();

                    return null;
                } catch (RenderFailedException|RenderBudgetExceededException $e) {
                    Notification::make()->danger()->title(__('documents.errors.preview_failed'))->body($e->getMessage())->send();

                    return null;
                }

                return response()->streamDownload(fn (): int => print ($pdf), $this->record->key.'.preview.pdf', ['Content-Type' => 'application/pdf']);
            });
    }

    private function dismissImportReportAction(): Action
    {
        return Action::make('dismissImportReport')->label(__('forms.import.report.dismiss'))->icon('heroicon-o-x-mark')->color('gray')
            ->visible(fn (): bool => $this->record->import_report !== null)
            ->authorize(fn (): bool => auth()->user()?->can('update', $this->record) ?? false)
            ->action(function (): void {
                app(DismissImportReport::class)->handle($this->record, $this->actor());
                Notification::make()->success()->title(__('forms.import.report.dismissed'))->send();
            });
    }

    private function exportDraftAction(): Action
    {
        return Action::make('exportDraft')->label(__('forms.actions.export_json'))->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('view', $this->record) ?? false)
            ->action(function (): StreamedResponse {
                $json = app(ExportDefinition::class)->handle($this->record, null, $this->actor());

                return response()->streamDownload(fn (): int => print ($json), $this->record->key.'.draft.form.json', ['Content-Type' => 'application/json']);
            });
    }

    private function publishAction(): Action
    {
        return Action::make('publish')->label(__('forms.actions.publish'))->icon('heroicon-o-rocket-launch')->color('success')
            ->disabled(fn (): bool => $this->hasLintErrors())
            ->tooltip(fn (): ?string => $this->hasLintErrors() ? (string) __('forms.errors.invalid_definition') : null)
            ->authorize(fn (): bool => auth()->user()?->can('publish', $this->record) ?? false)
            ->schema([Textarea::make('changelog')->label(__('forms.versions.changelog'))->required()->rows(3)])
            ->action(function (array $data): void {
                try {
                    // The draft is stored as part of the publish action, which writes the one audit entry.
                    $state = $this->validatedStateForPublish();
                    if ($state === null) {
                        return;
                    }
                    if (! $this->persist($this->record, $state, autosave: true)) {
                        return;
                    }
                    $version = app(PublishForm::class)->handle($this->record, (string) $data['changelog'], $this->actor(), expectedChecksum: $this->loadedChecksum);
                } catch (DraftChangedException) {
                    $this->notifyStale();

                    return;
                } catch (InvalidDefinitionException $e) {
                    $this->lint = $e->report->toArray();
                    Notification::make()->danger()->title(__('forms.errors.invalid_definition'))->send();

                    return;
                }
                $this->fillForm();
                Notification::make()->success()->title(__('forms.versions.published', ['version' => $version->version]))->send();
                $this->dispatch(VersionsRelationManager::REFRESH_EVENT);
            });
    }

    /**
     * The builder state, or null after reporting field errors: the errors sit on fields behind the
     * publish dialog, so the dialog closes and a notification says what to fix.
     *
     * @return array<string, mixed>|null
     */
    private function validatedStateForPublish(): ?array
    {
        try {
            $state = $this->form->getState();
        } catch (ValidationException $e) {
            $this->unmountAction(false);
            $this->setErrorBag($e->validator->errors());
            Notification::make()->danger()->title(__('forms.errors.fix_fields'))->body(collect($e->errors())->flatten()->first())->send();

            return null;
        }

        return is_array($state) ? $state : [];
    }

    public function hasLintErrors(): bool
    {
        if ($this->lint['schema_errors'] !== []) {
            return true;
        }

        return collect($this->lint['findings'])->contains(fn (array $f): bool => $f['severity'] === 'error');
    }

    /** @return array<string, string> data element keys for logic and calculation selects */
    public function fieldKeyOptions(): array
    {
        $keys = array_keys(KeyTypes::fromState(is_array($this->data) ? $this->data : []));
        sort($keys);

        return array_combine($keys, $keys);
    }

    /** @return array<string, string> */
    public function repeaterKeyOptions(): array
    {
        $keys = array_keys(array_filter(KeyTypes::fromState(is_array($this->data) ? $this->data : []), fn (string $type): bool => $type === 'repeater'));

        return array_combine($keys, $keys);
    }

    /** @return array<string, string> keys of the elements inside a repeater */
    public function rowKeyOptions(string $repeater): array
    {
        $definition = $this->mapper()->toDefinition(is_array($this->data) ? $this->data : []);
        foreach (DefinitionTools::elements($definition) as $element) {
            if (($element->key ?? null) === $repeater && is_array($element->elements ?? null)) {
                $keys = array_values(array_filter(array_map(fn (mixed $e): ?string => $e instanceof stdClass && is_string($e->key ?? null) ? $e->key : null, $element->elements)));

                return array_combine($keys, $keys);
            }
        }

        return [];
    }

    /** The unsaved builder state as a definition, for the live preview iframe. */
    public function previewDefinitionJson(): string
    {
        try {
            return Json::encode($this->mapper()->toDefinition(is_array($this->data) ? $this->data : []));
        } catch (InvalidArgumentException) {
            return Json::encode($this->record->draft_definition);
        }
    }

    public function draftJson(): string
    {
        return json_encode(Json::decode(Json::encode($this->record->draft_definition)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function refreshLint(stdClass $definition): void
    {
        $this->lint = app(DefinitionChecker::class)->check($definition, $this->record)->toArray();
    }

    private function mapper(): BuilderMapper
    {
        return new BuilderMapper(PublishedElementIds::of($this->record));
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
