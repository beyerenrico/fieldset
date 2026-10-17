<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Providers;

use SocialiteProviders\OpenIDConnect\Providers\GoogleProvider;
use stdClass;

/**
 * Google Workspace (ADR 0036). Google documents both `https://accounts.google.com` and `accounts.google.com`
 * as the id_token issuer, so either is accepted (strictly, nothing else). The `hd` and `email_verified`
 * rules are refusals with their own error codes and live in OidcProfileFactory.
 */
final class AppGoogleProvider extends GoogleProvider
{
    private const array ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    /** @return callable(string, stdClass): bool */
    protected function issuerValidator()
    {
        return static fn (string $expected, stdClass $payload): bool => in_array($payload->iss ?? null, self::ISSUERS, true);
    }
}
