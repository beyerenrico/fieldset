<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Providers;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Contracts\SsoProviderFactory;
use App\Domain\Identity\Sso\Enums\OidcFlavor;
use InvalidArgumentException;
use SocialiteProviders\Manager\Config;
use SocialiteProviders\OpenIDConnect\Provider as OidcProvider;

/**
 * Builds the Socialite provider of an organization's identity provider per request from its encrypted config
 * (ADR 0036, Runtime providers): the OIDC class by flavor (Entra, Google, generic) or the SAML provider, each
 * with the SSRF-guarded HTTP client. Nothing is registered with Socialite's manager: providers are tenant
 * data, not static config.
 */
final readonly class DynamicProviderFactory implements SsoProviderFactory
{
    public function __construct(private SsrfGuardedHttpClient $http) {}

    /** @throws InvalidArgumentException on an invalid configuration */
    public function oidc(IdentityProvider $p): OidcProvider
    {
        $settings = OidcSettings::of($p);
        $secret = (string) $p->client_secret;
        $class = match ($settings->flavor) {
            OidcFlavor::Entra => AppEntraProvider::class,
            OidcFlavor::Google => AppGoogleProvider::class,
            OidcFlavor::Generic => AppOidcProvider::class,
        };

        $provider = new $class(request(), $settings->clientId, $secret, $p->callbackUrl());
        $provider->setConfig(new Config($settings->clientId, $secret, $p->callbackUrl(), $settings->providerConfig()));
        $provider->setHttpClient($this->http->client());

        return $provider;
    }

    /**
     * The SAML provider with this app's request binding, audience, recipient and replay rules.
     *
     * @throws InvalidArgumentException on an invalid configuration
     */
    public function saml(IdentityProvider $p): AppSaml2Provider
    {
        $provider = new AppSaml2Provider(request());
        $provider->setConfig(new Config('', '', '', SamlSettings::of($p)->providerConfig($p)));
        $provider->setHttpClient($this->http->client());

        return $provider;
    }
}
