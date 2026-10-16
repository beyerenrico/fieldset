<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Pages;

use App\Domain\Forms\Actions\CreateForm;
use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\ImportDefinition;
use App\Domain\Forms\Jobs\ImportFillablePdf;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Support\InvalidDefinitionException;
use App\Domain\Forms\Templates\TemplateCatalog;
use App\Domain\Identity\Models\User;
use App\Filament\Admin\Resources\Forms\FormResource;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListForms extends ListRecords
{
    protected static string $resource = FormResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->blankAction(), $this->templateAction(), $this->importAction(), $this->importPdfAction()];
    }

    /** Fillable PDF → draft form, parsed by a queued job (BUILD_PROMPT §8.13). */
    private function importPdfAction(): Action
    {
        return Action::make('importPdf')->label(__('forms.import.action'))->icon('heroicon-o-document-arrow-up')->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('create', Form::class) ?? false)
            ->modalDescription(__('forms.import.action_help'))
            ->schema([
                FileUpload::make('file')->label(__('forms.import.file'))->required()
                    ->acceptedFileTypes(['application/pdf'])
                    ->maxSize(intdiv((int) config('forms.pdf_import.max_bytes'), 1024))
                    ->disk((string) config('forms.pdf_import.disk'))->visibility('private')
                    ->directory((string) config('forms.pdf_import.directory'))
                    ->storeFileNamesIn('file_name'),
                TextInput::make('name')->label(__('forms.form.name'))->maxLength(200)->helperText(__('forms.import.name_help')),
            ])
            ->action(function (array $data): void {
                $path = is_array($data['file']) ? (string) (array_values($data['file'])[0] ?? '') : (string) $data['file'];
                $fileName = is_array($data['file_name'] ?? null) ? (array_values($data['file_name'])[0] ?? null) : ($data['file_name'] ?? null);
                ImportFillablePdf::dispatch($this->actor(), $path, ($data['name'] ?? '') !== '' ? $data['name'] : null,
                    is_string($fileName) ? $fileName : null, app()->getLocale(), (string) config('forms.pdf_import.disk'));
                Notification::make()->info()->title(__('forms.import.queued'))->body(__('forms.import.queued_body'))->send();
            });
    }

    private function blankAction(): Action
    {
        return Action::make('createBlank')->label(__('forms.actions.create_blank'))->icon('heroicon-o-plus')
            ->authorize(fn (): bool => auth()->user()?->can('create', Form::class) ?? false)
            ->schema([
                TextInput::make('name')->label(__('forms.form.name'))->required()->maxLength(200),
                TextInput::make('category')->label(__('forms.form.category'))->maxLength(80),
            ])
            ->action(function (array $data): void {
                [$form] = app(CreateForm::class)->handle($this->actor(), $data['name'], null, $data['category'] ?? null);
                $this->redirect(FormResource::getUrl('edit', ['record' => $form]));
            });
    }

    private function templateAction(): Action
    {
        return Action::make('createFromTemplate')->label(__('forms.actions.create_from_template'))->icon('heroicon-o-rectangle-stack')
            ->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('create', Form::class) ?? false)
            ->schema([
                Select::make('template')->label(__('forms.actions.template'))->required()
                    ->options(fn (): array => collect(TemplateCatalog::bundled()->all(app()->getLocale()))
                        ->mapWithKeys(fn (array $t): array => [$t['key'] => $t['title']])->all()),
                TextInput::make('name')->label(__('forms.form.name'))->maxLength(200),
            ])
            ->action(function (array $data): void {
                $form = app(CreateFormFromTemplate::class)->handle($this->actor(), $data['template'], ($data['name'] ?? '') !== '' ? $data['name'] : null);
                $this->redirect(FormResource::getUrl('edit', ['record' => $form]));
            });
    }

    private function importAction(): Action
    {
        return Action::make('importJson')->label(__('forms.actions.import_json'))->icon('heroicon-o-arrow-up-tray')->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('create', Form::class) ?? false)
            ->schema([
                FileUpload::make('file')->label(__('forms.actions.file'))->acceptedFileTypes(['application/json', 'text/plain'])
                    ->maxSize(intdiv(ImportDefinition::MAX_BYTES, 1024))->storeFiles(false),
                Textarea::make('json')->label('JSON')->rows(6),
            ])
            ->action(function (array $data): void {
                $json = $this->uploadedJson($data['file'] ?? null) ?? (string) ($data['json'] ?? '');
                try {
                    [$form] = app(ImportDefinition::class)->handle($this->actor(), $json);
                } catch (InvalidArgumentException|InvalidDefinitionException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }
                $this->redirect(FormResource::getUrl('edit', ['record' => $form]));
            });
    }

    private function uploadedJson(mixed $file): ?string
    {
        $file = is_array($file) ? (array_values($file)[0] ?? null) : $file;

        return $file instanceof TemporaryUploadedFile || $file instanceof UploadedFile ? (string) file_get_contents($file->getRealPath()) : null;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
