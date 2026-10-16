<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Workflows\Pages;

use App\Filament\Admin\Resources\Workflows\WebhookEndpoints\WebhookEndpointResource;
use App\Filament\Admin\Resources\Workflows\WorkflowResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkflows extends ListRecords
{
    protected static string $resource = WorkflowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('webhooks')->label(__('workflows.webhooks.plural'))->icon('heroicon-o-globe-alt')->color('gray')
                ->visible(fn (): bool => WebhookEndpointResource::canViewAny())
                ->url(fn (): string => WebhookEndpointResource::getUrl()),
            CreateAction::make(),
        ];
    }
}
