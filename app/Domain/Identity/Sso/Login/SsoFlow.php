<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Enums\SsoClient;

/**
 * One SSO login attempt, kept in the initiating session (ADR 0036): the provider, the return target (an
 * enum, never a URL from the request), test mode, and for test mode the admin page to return to.
 */
final readonly class SsoFlow
{
    public function __construct(
        public string $id,
        public string $providerUuid,
        public SsoClient $client,
        public bool $test,
        public ?string $returnUrl,
        public int $startedAt,
    ) {}

    public function belongsTo(IdentityProvider $provider): bool
    {
        return hash_equals($this->providerUuid, $provider->uuid);
    }

    public function isExpired(): bool
    {
        return time() - $this->startedAt > config()->integer('sso.flow_ttl', 600);
    }

    /** @return array{id: string, provider: string, client: string, test: bool, return_url: string|null, started_at: int} */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'provider' => $this->providerUuid, 'client' => $this->client->value, 'test' => $this->test,
            'return_url' => $this->returnUrl, 'started_at' => $this->startedAt,
        ];
    }

    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_string($data['id'] ?? null) || ! is_string($data['provider'] ?? null)) {
            return null;
        }
        $client = SsoClient::tryFrom(is_string($data['client'] ?? null) ? $data['client'] : '');

        return $client === null ? null : new self(
            $data['id'],
            $data['provider'],
            $client,
            ($data['test'] ?? false) === true,
            is_string($data['return_url'] ?? null) ? $data['return_url'] : null,
            is_int($data['started_at'] ?? null) ? $data['started_at'] : 0,
        );
    }
}
