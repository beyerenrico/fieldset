<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Domain\Workflows\Webhooks\WebhookTarget;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates or updates a webhook endpoint. The URL is checked syntactically (scheme, host, no credentials,
 * no private IP literal); host names are resolved and checked before every delivery (WebhookTarget). A
 * new endpoint without a secret gets a random one; an empty secret on update keeps the current one.
 */
final class SaveWebhookEndpoint
{
    public const int SECRET_LENGTH = 40;

    /** @param  array{name: string, url: string, active?: bool, secret?: string|null}  $data */
    public function handle(?WebhookEndpoint $endpoint, array $data, User $actor): WebhookEndpoint
    {
        $url = trim($data['url']);
        $problem = WebhookTarget::syntaxProblem($url);
        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }
        $endpoint ??= new WebhookEndpoint(['created_by' => $actor->id]);
        $endpoint->fill(['name' => trim($data['name']), 'url' => $url, 'active' => (bool) ($data['active'] ?? true)]);
        $secret = trim((string) ($data['secret'] ?? ''));
        if ($secret !== '' || ! $endpoint->exists) {
            $endpoint->secret = $secret !== '' ? $secret : Str::random(self::SECRET_LENGTH);
        }
        $endpoint->save();

        return $endpoint;
    }
}
