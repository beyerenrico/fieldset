<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Enums;

/**
 * OIDC flavor rules (ADR 0036): Entra requires a tenant GUID and asserts `tid`; Google requires a verified
 * `hd` domain and `email_verified`; generic covers Keycloak, Authentik and Zitadel.
 */
enum OidcFlavor: string
{
    case Entra = 'entra';
    case Google = 'google';
    case Generic = 'generic';
}
