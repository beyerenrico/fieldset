<?php

declare(strict_types=1);

namespace App\Domain\Platform\Impersonation;

use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;

/** An operator signed in as an organization member (ADR 0041); lives in the session as a plain array. */
final readonly class Impersonation
{
    public function __construct(
        public int $operatorId,
        public int $targetId,
        public int $organizationId,
        public string $reason,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $expiresAt,
    ) {}

    /** @return array{operator_id: int, target_id: int, organization_id: int, reason: string, started_at: int, expires_at: int} */
    public function toArray(): array
    {
        return [
            'operator_id' => $this->operatorId,
            'target_id' => $this->targetId,
            'organization_id' => $this->organizationId,
            'reason' => $this->reason,
            'started_at' => $this->startedAt->getTimestamp(),
            'expires_at' => $this->expiresAt->getTimestamp(),
        ];
    }

    /** Null for anything that is not a complete payload (a tampered or outdated session never half-loads). */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data)) {
            return null;
        }
        foreach (['operator_id', 'target_id', 'organization_id', 'started_at', 'expires_at'] as $key) {
            if (! is_int($data[$key] ?? null)) {
                return null;
            }
        }
        if (! is_string($data['reason'] ?? null)) {
            return null;
        }

        return new self(
            $data['operator_id'], $data['target_id'], $data['organization_id'], $data['reason'],
            CarbonImmutable::createFromTimestamp($data['started_at']), CarbonImmutable::createFromTimestamp($data['expires_at']),
        );
    }

    public function expired(): bool
    {
        return CarbonImmutable::now()->greaterThanOrEqualTo($this->expiresAt);
    }

    public function remainingMinutes(): int
    {
        return max(0, (int) ceil(CarbonImmutable::now()->diffInSeconds($this->expiresAt, false) / 60));
    }

    /**
     * Audit properties of both events (`platform.impersonation_started` / `_ended`).
     *
     * @return array<string, int|string|null>
     */
    public function auditProperties(?User $operator, ?User $target): array
    {
        return [
            'operator_id' => $this->operatorId,
            'operator_email' => $operator?->email,
            'target_id' => $this->targetId,
            'target_email' => $target?->email,
            'organization_id' => $this->organizationId,
            'reason' => $this->reason,
            'started_at' => $this->startedAt->toIso8601String(),
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }
}
