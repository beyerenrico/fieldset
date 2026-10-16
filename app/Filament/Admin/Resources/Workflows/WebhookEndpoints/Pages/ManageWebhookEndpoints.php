<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Workflows\WebhookEndpoints\Pages;

use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Filament\Admin\Resources\Workflows\WebhookEndpoints\WebhookEndpointResource;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageWebhookEndpoints extends ManageRecords
{
    protected static string $resource = WebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(function (array $data): WebhookEndpoint {
                $endpoint = WebhookEndpointResource::save(null, $data);
                if (trim((string) ($data['secret'] ?? '')) === '') {
                    // A generated secret is shown once so the receiver can be configured.
                    Notification::make()->warning()->persistent()->title(__('workflows.webhooks.generated', ['secret' => $endpoint->secret]))->send();
                }

                return $endpoint;
            }),
        ];
    }
}
