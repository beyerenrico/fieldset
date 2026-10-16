<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SavedReports\Pages;

use App\Filament\Admin\Resources\SavedReports\SavedReportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSavedReports extends ListRecords
{
    protected static string $resource = SavedReportResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
