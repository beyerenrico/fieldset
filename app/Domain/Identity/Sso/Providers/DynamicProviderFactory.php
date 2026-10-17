<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Providers;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Contracts\SsoProviderFactory;
use LogicException;
use SocialiteProviders\OpenIDConnect\Provider as OidcProvider;
use SocialiteProviders\Saml2\Provider as SamlProvider;

/** Skeleton stub (M7, owner A). */
final readonly class DynamicProviderFactory implements SsoProviderFactory
{
    public function oidc(IdentityProvider $p): OidcProvider
    {
        throw new LogicException('not implemented');
    }

    public function saml(IdentityProvider $p): SamlProvider
    {
        throw new LogicException('not implemented');
    }
}
