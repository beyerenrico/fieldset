<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Workflows\WebhookEndpoints\Pages;

use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Filament\Admin\Resources\Workflows\WebhookEndpoints\WebhookEndpointResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;

/**
 * Webhook endpoints. A secret generated on create is shown once in a modal with a copyable field (the
 * receiver needs it to check signatures; it is never shown again).
 */
class ManageWebhookEndpoints extends ManageRecords
{
    protected static string $resource = WebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(fn (array $data): WebhookEndpoint => WebhookEndpointResource::save(null, $data))
                ->after(function (WebhookEndpoint $record, array $data): void {
                    if (trim((string) ($data['secret'] ?? '')) === '') {
                        $this->replaceMountedAction('generatedSecret', ['endpoint' => $record->id]);
                    }
                }),
        ];
    }

    /** Shows the generated signing secret of a just-created endpoint (the user may update it). */
    public function generatedSecretAction(): Action
    {
        return Action::make('generatedSecret')
            ->modalHeading(__('workflows.webhooks.generated_title'))
            ->modalDescription(__('workflows.webhooks.generated'))
            ->modalSubmitAction(false)->modalCancelActionLabel(__('workflows.webhooks.generated_done'))
            ->fillForm(fn (array $arguments): array => ['secret' => $this->endpoint($arguments)?->secret])
            ->schema([
                TextInput::make('secret')->label(__('workflows.webhooks.secret'))->readOnly()->copyable()
                    ->extraInputAttributes(['class' => 'font-mono']),
            ]);
    }

    /** @param  array<string, mixed>  $arguments */
    private function endpoint(array $arguments): ?WebhookEndpoint
    {
        $endpoint = WebhookEndpoint::query()->find((int) ($arguments['endpoint'] ?? 0));

        return $endpoint instanceof WebhookEndpoint && (auth()->user()?->can('update', $endpoint) ?? false) ? $endpoint : null;
    }
}
