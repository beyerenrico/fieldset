<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Designs\Pages;

use App\Domain\Documents\Designs\Actions\PublishDesign;
use App\Domain\Documents\Designs\Actions\SaveDesignDraft;
use App\Domain\Documents\Designs\DesignSettings;
use App\Domain\Documents\Models\Design;
use App\Domain\Documents\Rendering\PreviewDocument;
use App\Domain\Documents\Rendering\RenderBudgetExceededException;
use App\Domain\Documents\Rendering\RenderFailedException;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Filament\Admin\Resources\Designs\DesignResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Design editor: saves the draft, previews it on a sample form and publishes immutable versions
 * (BUILD_PROMPT §8.5, §8.10).
 *
 * @property Design $record
 */
class EditDesign extends EditRecord
{
    protected static string $resource = DesignResource::class;

    public function form(Schema $schema): Schema
    {
        return DesignForm::configure($schema);
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ['name' => $this->record->name, 'key' => $this->record->key, 'settings' => $this->record->draftSettings()->toArray()];
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Design $record */
        try {
            return app(SaveDesignDraft::class)->handle($record, (string) ($data['name'] ?? ''), $this->settings($data), $this->actor());
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt($e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function getHeaderActions(): array
    {
        return [$this->publishAction(), $this->previewAction()];
    }

    private function publishAction(): Action
    {
        return Action::make('publish')->label(__('documents.actions.publish'))->icon('heroicon-o-rocket-launch')->color('success')
            ->authorize(fn (): bool => auth()->user()?->can('publish', $this->record) ?? false)
            ->schema([Textarea::make('changelog')->label(__('documents.versions.changelog'))->required()->rows(3)])
            ->action(function (array $data): void {
                $state = $this->form->getState();
                $design = app(SaveDesignDraft::class)->handle($this->record, (string) ($state['name'] ?? ''), $this->settings($state), $this->actor());
                $version = app(PublishDesign::class)->handle($design, (string) $data['changelog'], $this->actor());
                $this->fillForm();
                Notification::make()->success()->title(__('documents.versions.published', ['version' => $version->version]))->send();
            });
    }

    /** Renders the unsaved settings on a sample form and streams the PDF. */
    private function previewAction(): Action
    {
        return Action::make('previewPdf')->label(__('documents.actions.preview_pdf'))->icon('heroicon-o-eye')->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('view', $this->record) ?? false)
            ->action(function (): ?StreamedResponse {
                // getState(): validated and dehydrated (the rich editors' raw state is a TipTap document, not HTML).
                $settings = DesignSettings::fromArray($this->settings($this->form->getState()));
                try {
                    $pdf = app(PreviewDocument::class)->forSettings($settings, $this->organization(), $this->actor())->pdf;
                } catch (RenderFailedException|RenderBudgetExceededException $e) {
                    Notification::make()->danger()->title(__('documents.errors.preview_failed'))->body($e->getMessage())->send();

                    return null;
                }

                return response()->streamDownload(fn (): int => print ($pdf), $this->record->key.'.preview.pdf', ['Content-Type' => 'application/pdf']);
            });
    }

    /**
     * @param  array<string, mixed>|Arrayable<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function settings(array|Arrayable $data): array
    {
        $data = $data instanceof Arrayable ? $data->toArray() : $data;
        $settings = $data['settings'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    private function organization(): Organization
    {
        /** @var Organization $organization */
        $organization = Filament::getTenant();

        return $organization;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
