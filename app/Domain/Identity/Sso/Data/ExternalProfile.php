<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Data;

use App\Domain\Identity\Sso\Enums\IdentityProviderType;

/**
 * The verified identity an OIDC id_token or a signed SAML assertion yields; the single input of
 * CompleteSsoLogin for both protocols (ADR 0036).
 */
final readonly class ExternalProfile
{
    /**
     * @param  array<string, mixed>  $claims  sanitized: groups, hd, tid, amr
     */
    public function __construct(
        public IdentityProviderType $via,
        public string $issuer,
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name,
        public array $claims,
    ) {}
}
