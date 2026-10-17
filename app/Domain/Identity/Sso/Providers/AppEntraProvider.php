<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Providers;

use InvalidArgumentException;
use SocialiteProviders\OpenIDConnect\Providers\EntraProvider;

/**
 * Microsoft Entra ID for one directory (ADR 0036): the factory pins `tenant` (a GUID) and the issuer
 * `https://login.microsoftonline.com/{tenant}/v2.0`; on top of the package's checks the token's `tid` must
 * be that tenant. The email comes from `preferred_username` (the UPN, a tenant-verified domain), never from
 * the spoofable `email` claim (package default).
 */
final class AppEntraProvider extends EntraProvider
{
    public const string TID_MISMATCH = 'JWT: tid does not match the configured tenant.';

    /**
     * @param  \stdClass  $payload
     */
    protected function validateIdTokenClaims($payload, ?string $alg, ?string $accessToken): void
    {
        parent::validateIdTokenClaims($payload, $alg, $accessToken);

        $tid = $payload->tid ?? null;
        $tenant = (string) $this->getConfig('tenant');
        if (! is_string($tid) || $tenant === '' || strcasecmp($tid, $tenant) !== 0) {
            throw new InvalidArgumentException(self::TID_MISMATCH, 401);
        }
    }
}
