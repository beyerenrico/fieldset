<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Health;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Contracts\ProviderHealthCheck;
use App\Domain\Identity\Sso\Data\ProviderHealth;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Providers\DynamicProviderFactory;
use App\Domain\Identity\Sso\Providers\FailureClassifier;
use App\Domain\Identity\Sso\Providers\HealthProblem;
use App\Domain\Identity\Sso\Providers\OidcSettings;
use App\Domain\Identity\Sso\Providers\SsrfGuardedHttpClient;
use LightSaml\Model\Metadata\KeyDescriptor;
use Throwable;

/**
 * Provider health (ADR 0036, checks): OIDC — the discovery document is reachable, names the configured issuer
 * and its endpoints, and the JWKS holds keys; SAML — the IdP metadata (URL, XML or manual settings) parses and
 * has an SSO service and a signing certificate. Fresh fetches through the SSRF-guarded client (a SAML metadata
 * cache is dropped first). Reasons match the login path's misconfiguration reasons.
 */
final readonly class FetchProviderHealth implements ProviderHealthCheck
{
    public function __construct(
        private SsrfGuardedHttpClient $http,
        private DynamicProviderFactory $factory,
        private FailureClassifier $classifier,
    ) {}

    public function check(IdentityProvider $p): ProviderHealth
    {
        try {
            $p->type === IdentityProviderType::Oidc ? $this->oidc($p) : $this->saml($p);
        } catch (HealthProblem $e) {
            return new ProviderHealth(false, $e->reason, $e->getMessage());
        } catch (Throwable $e) {
            $failure = $this->classifier->classify($e, $p->type);

            return new ProviderHealth(false, $failure->misconfiguration ?? ($p->type === IdentityProviderType::Saml ? 'metadata' : 'discovery'), $failure->detail);
        }

        return new ProviderHealth(true);
    }

    private function oidc(IdentityProvider $provider): void
    {
        $settings = OidcSettings::of($provider);
        $document = $this->json($settings->discoveryUrl(), 'discovery');
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            if (! is_string($document[$key] ?? null) || $document[$key] === '') {
                throw new HealthProblem('discovery', "The discovery document has no {$key}.");
            }
        }
        if (($document['issuer'] ?? null) !== $settings->issuer && ! $this->googleIssuer($settings, $document)) {
            throw new HealthProblem('issuer', 'The discovery document names another issuer.');
        }

        $keys = $this->json((string) $document['jwks_uri'], 'jwks')['keys'] ?? null;
        if (! is_array($keys) || $keys === []) {
            throw new HealthProblem('jwks', 'The JWKS holds no keys.');
        }
    }

    private function saml(IdentityProvider $provider): void
    {
        $saml = $this->factory->saml($provider);
        $saml->clearIdentityProviderMetadataCache();
        $descriptor = $saml->getIdentityProviderEntityDescriptor()->getFirstIdpSsoDescriptor();

        if ($descriptor === null || $descriptor->getAllSingleSignOnServices() === []) {
            throw new HealthProblem('metadata', 'The IdP metadata has no SSO service.');
        }
        $signing = array_filter($descriptor->getAllKeyDescriptors() ?? [], static fn (KeyDescriptor $key): bool => in_array($key->getUse(), [null, '', KeyDescriptor::USE_SIGNING], true));
        if ($signing === []) {
            throw new HealthProblem('metadata', 'The IdP metadata has no signing certificate.');
        }
    }

    /** @return array<array-key, mixed> */
    private function json(string $url, string $reason): array
    {
        $response = $this->http->client()->get($url, ['http_errors' => false]);
        if ($response->getStatusCode() !== 200) {
            throw new HealthProblem($reason, "{$url} answered HTTP {$response->getStatusCode()}.");
        }

        $data = json_decode((string) $response->getBody(), true);
        if (! is_array($data)) {
            throw new HealthProblem($reason, "{$url} did not answer a JSON object.");
        }

        return $data;
    }

    /** @param  array<array-key, mixed>  $document */
    private function googleIssuer(OidcSettings $settings, array $document): bool
    {
        return $settings->issuer === 'https://accounts.google.com' && ($document['issuer'] ?? null) === 'accounts.google.com';
    }
}
