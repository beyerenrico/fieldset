<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Data;

use App\Domain\Identity\Models\IdentityProvider;

/**
 * Home-realm discovery outcome (ADR 0036): an SSO redirect for a verified domain of an organization with
 * an active provider, otherwise password mode. Never reveals whether a user exists.
 */
final readonly class DiscoveryResult
{
    public function __construct(
        public ?IdentityProvider $provider,
        public ?string $redirectUrl,
        public bool $enforced,
    ) {}

    /** Password mode: no provider for the email's domain. */
    public static function password(): self
    {
        return new self(null, null, false);
    }

    public function isSso(): bool
    {
        return $this->provider instanceof IdentityProvider && $this->redirectUrl !== null;
    }
}
