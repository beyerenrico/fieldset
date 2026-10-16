<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Designs\Pages;

use App\Domain\Documents\Designs\Actions\CreateDesign;
use App\Domain\Documents\Models\Design;
use App\Domain\Identity\Models\User;
use App\Filament\Admin\Resources\Designs\DesignResource;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;

class ListDesigns extends ListRecords
{
    protected static string $resource = DesignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createDesign')->label(__('documents.actions.create'))->icon('heroicon-o-plus')
                ->authorize(fn (): bool => auth()->user()?->can('create', Design::class) ?? false)
                ->schema([TextInput::make('name')->label(__('documents.designs.name'))->required()->maxLength(120)])
                ->action(function (array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    $design = app(CreateDesign::class)->handle((string) $data['name'], $actor);
                    $this->redirect(DesignResource::getUrl('edit', ['record' => $design]));
                }),
        ];
    }
}
