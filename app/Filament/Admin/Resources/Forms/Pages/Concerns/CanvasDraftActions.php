<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Pages\Concerns;

use App\Domain\Documents\Rendering\PreviewDocument;
use App\Domain\Documents\Rendering\RenderBudgetExceededException;
use App\Domain\Documents\Rendering\RenderFailedException;
use App\Domain\Forms\Actions\ExportDefinition;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Support\DefinitionChecker;
use App\Domain\Forms\Support\DraftChangedException;
use App\Domain\Forms\Support\InvalidDefinitionException;
use App\Domain\Forms\Support\PublishedElementIds;
use App\Domain\Identity\Models\Organization;
use App\Filament\Admin\Resources\Forms\Pages\CanvasBuilder;
use Fieldset\Definition\InvalidDefinitionException as InvalidSpecDefinition;
use Fieldset\Json\Json;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The canvas builder's page actions on the STORED draft (ADR 0037): publish, Preview PDF and export. The
 * client mounts them with `{checksum}` after flushing its autosave; a different stored draft is refused,
 * and PublishForm checks the checksum again under its row lock, so a save in between is never reverted.
 *
 * @mixin CanvasBuilder
 */
trait CanvasDraftActions
{
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
                $checksum = $this->guardDraft($action, $arguments, lint: false);
                try {
                    $version = app(PublishForm::class)->handle($this->formRecord(), (string) $data['changelog'], $this->actor(), expectedChecksum: $checksum);
                } catch (DraftChangedException) {
                    $this->notifyStaleDraft();

                    return;
                } catch (InvalidDefinitionException) {
                    Notification::make()->danger()->title(__('forms.errors.invalid_definition'))->send();

                    return;
                }
                Notification::make()->success()->title(__('forms.versions.published', ['version' => $version->version]))->send();
                $this->dispatch(CanvasBuilder::PUBLISHED, version: $version->version, lockedIds: PublishedElementIds::of($this->formRecord()));
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

    /**
     * Actions run on the stored draft, so they refuse a checksum other than the stored one (the client
     * then saves or reloads first); publishing also refuses a draft with lint errors. Returns the checksum.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function guardDraft(Action $action, array $arguments, bool $lint): string
    {
        $form = $this->formRecord()->refresh();
        $checksum = is_string($arguments['checksum'] ?? null) ? $arguments['checksum'] : '';
        if (! hash_equals(Json::checksum($form->draft_definition), $checksum)) {
            $this->notifyStaleDraft();
            $action->cancel();
        }
        if ($lint && app(DefinitionChecker::class)->check($form->draft_definition, $form)->hasErrors()) {
            Notification::make()->danger()->title(__('forms.errors.invalid_definition'))->send();
            $action->cancel();
        }

        return $checksum;
    }

    private function notifyStaleDraft(): void
    {
        Notification::make()->danger()->title(__('forms.canvas.stale_title'))->body(__('forms.canvas.stale_action'))->send();
    }
}
