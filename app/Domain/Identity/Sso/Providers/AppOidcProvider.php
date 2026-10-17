<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Providers;

use InvalidArgumentException;
use SocialiteProviders\OpenIDConnect\Provider;

/**
 * Generic OIDC (Keycloak, Authentik, Zitadel; ADR 0036). The package already validates the id_token
 * (signature against the JWKS with rotation refetch, algorithm allow-list, iss, aud, azp, exp/nbf/iat,
 * one-time nonce, at_hash) and sends PKCE S256. The only change: `sso.allow_insecure_idp` (dev/CI) also
 * allows a plain-http issuer on a non-loopback host such as `http://keycloak:8080`.
 */
final class AppOidcProvider extends Provider
{
    protected function getBaseUrl(): string
    {
        if (! (bool) config('sso.allow_insecure_idp')) {
            return parent::getBaseUrl();
        }

        $baseUrl = rtrim((string) $this->getConfig('base_url'), '/');
        if (! in_array(strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('OIDC: base_url must be an http(s) URL.');
        }

        return $baseUrl;
    }
}
