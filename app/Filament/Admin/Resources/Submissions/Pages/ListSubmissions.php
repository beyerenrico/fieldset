<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Pages;

use App\Filament\Admin\Pages\FormExplorer;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSubmissions extends ListRecords
{
    protected static string $resource = SubmissionResource::class;

    /** Link to the submission explorer (M5-T01), for the form chosen in the filter when there is one. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('explore')->label(__('reports.explorer.open'))->icon(Heroicon::OutlinedTableCells)->color('gray')
                ->visible(fn (): bool => FormExplorer::canAccess())
                ->url(fn (): string => FormExplorer::getUrl(array_filter(['form' => $this->tableFilters['form_id']['value'] ?? null]))),
        ];
    }
}
