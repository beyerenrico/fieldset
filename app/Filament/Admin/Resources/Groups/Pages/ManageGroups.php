<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Groups\Pages;

use App\Filament\Admin\Resources\Groups\GroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageGroups extends ManageRecords
{
    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
