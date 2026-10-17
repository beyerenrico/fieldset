<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Data;

/** Result of a provider health check (discovery document, JWKS or SAML metadata reachable and valid). */
final readonly class ProviderHealth
{
    public function __construct(
        public bool $ok,
        public ?string $reason = null,
        public ?string $detail = null,
    ) {}
}
