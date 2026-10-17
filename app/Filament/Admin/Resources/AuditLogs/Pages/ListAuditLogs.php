<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuditLogs\Pages;

use App\Domain\Audit\Export\ExportAuditLog;
use App\Domain\Audit\Query\AuditLogFilters;
use App\Domain\Identity\Models\User;
use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The audit log list with "Export CSV" for the current filters (§8.11). */
class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')->label(__('audit.export.action'))->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(function (): ?StreamedResponse {
                    $user = Filament::auth()->user();
                    if (! $user instanceof User) {
                        return null;
                    }
                    $filters = AuditLogFilters::fromTableFilters($this->tableFilters ?? [], SubmissionResource::displayTimezone());
                    try {
                        $result = app(ExportAuditLog::class)->handle($user, $filters, app()->getLocale());
                    } catch (ValidationException $e) {
                        Notification::make()->warning()->title(collect($e->errors())->flatten()->first())->send();

                        return null;
                    }
                    if ($result instanceof StreamedResponse) {
                        return $result;
                    }
                    Notification::make()->success()->title(__('audit.export.queued'))->send();

                    return null;
                }),
        ];
    }
}
