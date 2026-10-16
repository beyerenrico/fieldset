<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Members\Actions;

use App\Domain\Identity\Invitations\BulkInvitationPlanner;
use App\Domain\Identity\Invitations\BulkInvitationReport;
use App\Domain\Identity\Invitations\CsvInvitationException;
use App\Domain\Identity\Invitations\CsvInvitationParser;
use App\Domain\Identity\Invitations\SendBulkInvitations;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * CSV bulk invitations on the Members page (BUILD_PROMPT §8.12, M3-T11): upload → per-row report →
 * send the valid rows. The file is never stored; it is re-read and re-checked on submit.
 */
final class ImportMembersCsvAction
{
    public static function make(): Action
    {
        return Action::make('importCsv')
            ->label(__('identity.csv_import.action'))
            ->icon('heroicon-o-arrow-up-tray')->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('create', Membership::class) ?? false)
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel(__('identity.csv_import.submit'))
            ->steps([
                Step::make('upload')->label(__('identity.csv_import.step_upload'))->schema([
                    FileUpload::make('file')->label(__('identity.csv_import.file'))->required()->storeFiles(false)
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->maxSize(intdiv(CsvInvitationParser::MAX_BYTES, 1024))
                        ->helperText(__('identity.csv_import.help', ['max' => CsvInvitationParser::MAX_ROWS])),
                ])->afterValidation(function (Get $get): void {
                    self::reportOrNotify($get('file'));
                }),
                Step::make('review')->label(__('identity.csv_import.step_review'))->schema([
                    View::make('filament.members.csv-invitation-report')
                        ->viewData(fn (Get $get): array => ['report' => self::report($get('file'))]),
                ]),
            ])
            ->action(function (array $data): void {
                $report = self::reportOrNotify($data['file'] ?? null);
                if ($report->valid() === []) {
                    Notification::make()->warning()->title(__('identity.csv_import.nothing_to_send'))->send();

                    throw new Halt;
                }
                self::send($report);
            });
    }

    private static function send(BulkInvitationReport $report): void
    {
        /** @var Organization $organization */
        $organization = Filament::getTenant();
        /** @var User $actor */
        $actor = auth()->user();
        $result = app(SendBulkInvitations::class)->handle($actor, $organization, $report);

        $body = array_map(fn (int $line, string $message): string => __('identity.csv_import.failed', ['line' => $line, 'message' => $message]), array_keys($result['failed']), $result['failed']);
        if ($report->invalid() !== []) {
            array_unshift($body, __('identity.csv_import.skipped', ['count' => count($report->invalid())]));
        }
        Notification::make()->success()->title(__('identity.csv_import.sent', ['count' => $result['invited']]))
            ->body($body !== [] ? implode("\n", $body) : null)->send();
    }

    /** Report for the review step; null while there is no readable file. */
    private static function report(mixed $file): ?BulkInvitationReport
    {
        try {
            return self::plan($file);
        } catch (CsvInvitationException) {
            return null;
        }
    }

    private static function reportOrNotify(mixed $file): BulkInvitationReport
    {
        try {
            return self::plan($file);
        } catch (CsvInvitationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt;
        }
    }

    /** @throws CsvInvitationException */
    private static function plan(mixed $file): BulkInvitationReport
    {
        $file = is_array($file) ? (array_values($file)[0] ?? null) : $file;
        if (! $file instanceof TemporaryUploadedFile && ! $file instanceof UploadedFile) {
            throw new CsvInvitationException(__('identity.csv_import.errors.unreadable'));
        }
        $contents = file_get_contents($file->getRealPath());
        /** @var Organization $organization */
        $organization = Filament::getTenant();
        /** @var User $actor */
        $actor = auth()->user();

        return app(BulkInvitationPlanner::class)->fromCsv($actor, $organization, $contents === false ? '' : $contents);
    }
}
