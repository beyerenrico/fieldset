<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Providers\DynamicProviderFactory;
use Illuminate\Http\Response;

/**
 * GET /auth/sso/{provider}/saml/metadata (ADR 0036): SP metadata — entity id (this URL), the HTTP-POST ACS,
 * NameID format, WantAssertionsSigned and, when `sso.saml` has a key pair, the signing/encryption cert.
 */
final class SamlMetadataController
{
    public function __invoke(IdentityProvider $provider, DynamicProviderFactory $factory): Response
    {
        abort_unless($provider->type === IdentityProviderType::Saml, 404);

        return $factory->saml($provider)->getServiceProviderMetadata();
    }
}
