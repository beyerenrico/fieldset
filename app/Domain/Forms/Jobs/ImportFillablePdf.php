<?php

declare(strict_types=1);

namespace App\Domain\Forms\Jobs;

use App\Domain\Forms\Import\ImportPdfForm;
use App\Domain\Forms\Import\PdfImportException;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Support\InvalidDefinitionException;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Forms\FormResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Traits\Localizable;
use Throwable;

/**
 * Queued import of an uploaded fillable PDF (BUILD_PROMPT §8.13). The tenant travels in the hidden
 * Context (§6.2). The upload is read from the private disk and always deleted afterwards; the user
 * gets a database notification on success or failure.
 */
final class ImportFillablePdf implements ShouldQueue
{
    use Localizable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public User $user,
        public string $path,
        public ?string $name = null,
        public ?string $fileName = null,
        public string $locale = 'en',
        public string $disk = 'local',
    ) {}

    public function handle(ImportPdfForm $import, TenantContext $tenant): void
    {
        $tenant->require();

        $this->withLocale($this->locale, function () use ($import, $tenant): void {
            try {
                [$form, $report] = $import->handle($this->user, Storage::disk($this->disk)->path($this->path), $this->name, $this->fileName);
                $this->notifySuccess($form, $report->stats['unmapped'] ?? 0, $tenant->organization());
            } catch (PdfImportException|InvalidDefinitionException $e) {
                $this->notifyFailure($e->getMessage());
            } finally {
                Storage::disk($this->disk)->delete($this->path);
            }
        });
    }

    /** Unexpected errors: tell the user, keep the details in the log. */
    public function failed(?Throwable $exception): void
    {
        Storage::disk($this->disk)->delete($this->path);
        Log::error('PDF import failed', ['path' => $this->path, 'exception' => $exception?->getMessage()]);
        $this->withLocale($this->locale, fn () => $this->notifyFailure((string) __('forms.import.errors.reader_failed')));
    }

    private function notifySuccess(Form $form, int $unmapped, ?Organization $organization): void
    {
        Notification::make()
            ->success()
            ->title(__('forms.import.notification.success_title', ['name' => $form->name]))
            ->body(trans_choice('forms.import.notification.success_body', $unmapped, ['count' => $unmapped]))
            ->actions([
                Action::make('open')->label(__('forms.import.notification.open'))->button()
                    ->url(FormResource::getUrl('edit', ['record' => $form], panel: 'admin', tenant: $organization)),
            ])
            ->sendToDatabase($this->user);
    }

    private function notifyFailure(string $message): void
    {
        Notification::make()
            ->danger()
            ->title(__('forms.import.notification.failure_title', ['file' => $this->fileName ?? basename($this->path)]))
            ->body($message)
            ->sendToDatabase($this->user);
    }
}
