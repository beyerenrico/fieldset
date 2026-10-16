<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Webhooks;

use App\Domain\Workflows\Engine\StepFailedException;
use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Support\Network\HostResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Posts a signed JSON body to a webhook endpoint (§10 "Outbound webhooks"): `X-Signature: sha256=<hex
 * HMAC-SHA256 of "{timestamp}.{body}" with the endpoint secret>` and `X-Timestamp` (Unix seconds); receivers
 * should reject requests older than 5 minutes. Timeouts, connection errors, 408/425/429 and 5xx are retried
 * (the step job's exponential backoff); redirects and other 4xx answers fail at once.
 */
final readonly class WebhookClient
{
    public function __construct(private HostResolver $resolver) {}

    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public function post(WebhookEndpoint $endpoint, string $body, string $deliveryId): Response
    {
        $target = WebhookTarget::resolve($endpoint->url, $this->resolver);
        $timestamp = (string) now()->getTimestamp();

        try {
            $response = Http::withOptions($target->options())
                ->timeout(max(1, (int) config('workflows.webhooks.timeout', 10)))
                ->connectTimeout(max(1, (int) config('workflows.webhooks.connect_timeout', 5)))
                ->withHeaders([
                    'User-Agent' => config('app.name').' Webhooks',
                    'X-Timestamp' => $timestamp,
                    'X-Signature' => self::signature($endpoint->secret, $timestamp, $body),
                    'X-Webhook-Id' => $deliveryId,
                ])
                ->withBody($body, 'application/json')
                ->post($target->url);
        } catch (ConnectionException $e) {
            throw new RuntimeException(__('workflows.errors.webhook_connection', ['error' => $e->getMessage()]), 0, $e);
        }

        return $this->checked($response);
    }

    private function checked(Response $response): Response
    {
        $status = $response->status();
        if ($response->successful()) {
            return $response;
        }
        $message = (string) __('workflows.errors.webhook_status', ['status' => $status]);
        if ($response->redirect() || ($status >= 400 && $status < 500 && ! in_array($status, [408, 425, 429], true))) {
            throw new StepFailedException($message);
        }

        throw new RuntimeException($message);
    }
}
