<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Enums;

/** Protocol of an organization's identity provider (ADR 0036). */
enum IdentityProviderType: string
{
    case Oidc = 'oidc';
    case Saml = 'saml';
}
