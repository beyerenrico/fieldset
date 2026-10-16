<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Steps;

use App\Domain\Workflows\Engine\StepContext;
use App\Domain\Workflows\Engine\StepFailedException;
use App\Domain\Workflows\Engine\StepResult;
use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Domain\Workflows\Webhooks\WebhookClient;
use App\Domain\Workflows\Webhooks\WebhookPayload;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * `webhook`: POSTs the signed event payload to an active endpoint of the organization (config `endpoint_id`).
 * An `in_flight` marker is saved before the POST and removed when the attempt is known to have failed; a
 * re-claimed step that still finds it (the worker died mid-request, outcome unknown) does not POST again but
 * fails, so an administrator decides — a manual retry clears the marker and sends (ADR 0032). Known failures
 * (timeouts, 5xx) are retried: delivery stays at-least-once, receivers dedupe by `X-Webhook-Id`.
 */
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

        $response = $this->deliver($context, $endpoint);

        return StepResult::completed(
            __('workflows.log.webhook_delivered', ['name' => $endpoint->name, 'status' => $response->status()]),
            ['endpoint_id' => $endpoint->id, 'status' => $response->status()],
        );
    }

    private function deliver(StepContext $context, WebhookEndpoint $endpoint): Response
    {
        $step = $context->step;
        if (isset($step->output['in_flight'])) {
            throw new StepFailedException(__('workflows.errors.webhook_interrupted'));
        }
        $step->mergeOutput(['in_flight' => ['attempt' => $step->attempts, 'at' => now()->toIso8601String()]]);
        $step->save();
        try {
            $response = $this->client->post($endpoint, WebhookPayload::encode($context), WebhookPayload::deliveryId($context));
        } catch (Throwable $e) {
            $step->forgetOutput('in_flight');   // known failure: the next attempt may send again
            $step->save();

            throw $e;
        }
        $step->forgetOutput('in_flight');

        return $response;
    }
}
