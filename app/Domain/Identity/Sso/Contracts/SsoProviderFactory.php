<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Contracts;

use App\Domain\Identity\Models\IdentityProvider;
use SocialiteProviders\OpenIDConnect\Provider as OidcProvider;
use SocialiteProviders\Saml2\Provider as SamlProvider;

/** Builds a Socialite provider per request from the stored config, with an SSRF-guarded HTTP client. */
interface SsoProviderFactory
{
    public function oidc(IdentityProvider $p): OidcProvider;

    public function saml(IdentityProvider $p): SamlProvider;
}
