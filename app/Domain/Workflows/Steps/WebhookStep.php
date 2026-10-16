<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Steps;

use App\Domain\Workflows\Engine\StepContext;
use App\Domain\Workflows\Engine\StepFailedException;
use App\Domain\Workflows\Engine\StepResult;
use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Domain\Workflows\Webhooks\WebhookClient;
use App\Domain\Workflows\Webhooks\WebhookPayload;

/** `webhook`: POSTs the signed event payload to an active endpoint of the organization (config `endpoint_id`). */
final readonly class WebhookStep implements StepHandler
{
    public function __construct(private WebhookClient $client) {}

    public function execute(StepContext $context): StepResult
    {
        $id = $context->int('endpoint_id');
        $endpoint = $id === null ? null : WebhookEndpoint::query()->find($id);
        if (! $endpoint instanceof WebhookEndpoint) {
            throw new StepFailedException(__('workflows.errors.endpoint_missing'));
        }
        if (! $endpoint->active) {
            throw new StepFailedException(__('workflows.errors.endpoint_inactive', ['name' => $endpoint->name]));
        }

        $response = $this->client->post($endpoint, WebhookPayload::encode($context), WebhookPayload::deliveryId($context));

        return StepResult::completed(
            __('workflows.log.webhook_delivered', ['name' => $endpoint->name, 'status' => $response->status()]),
            ['endpoint_id' => $endpoint->id, 'status' => $response->status()],
        );
    }
}
