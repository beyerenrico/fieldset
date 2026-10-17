<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Providers;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Enums\OidcFlavor;
use InvalidArgumentException;

/**
 * The OIDC settings of an identity provider, read from its encrypted `config` (ADR 0036, Runtime providers).
 *
 * Config keys: `flavor` (entra|google|generic), `client_id`, `scopes` (list), `groups_claim` (default
 * "groups"); Entra: `tenant` (GUID, required); generic: `issuer` (required; the expected `iss` and, unless
 * `discovery_url` is set, the base of `/.well-known/openid-configuration`), `discovery_url` (optional, for
 * an IdP that the server reaches under another host than the browser, e.g. Keycloak in Docker),
 * `trust_unverified_email` (bool, alias `allow_unverified_email`; accept emails without `email_verified`,
 * audited on every login as `email_verified_by: trusted`).
 */
final readonly class OidcSettings
{
    public const string CONFIG_ERROR = 'SSO config:';

    private const string ENTRA_BASE = 'https://login.microsoftonline.com/';

    private const string GOOGLE_ISSUER = 'https://accounts.google.com';

    private const string GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @param  list<string>  $scopes */
    public function __construct(
        public OidcFlavor $flavor,
        public string $baseUrl,
        public string $issuer,
        public ?string $tenant,
        public string $clientId,
        public array $scopes,
        public string $groupsClaim,
        public bool $trustUnverifiedEmail,
    ) {}

    /** @throws InvalidArgumentException on an incomplete or invalid configuration */
    public static function of(IdentityProvider $provider): self
    {
        $config = $provider->config;
        $flavor = OidcFlavor::tryFrom(self::string($config, 'flavor') ?? OidcFlavor::Generic->value)
            ?? throw self::invalid('unknown OIDC flavor');
        $tenant = $flavor === OidcFlavor::Entra ? self::tenant($config) : null;
        [$baseUrl, $issuer] = match ($flavor) {
            OidcFlavor::Entra => [self::ENTRA_BASE.$tenant.'/v2.0', self::ENTRA_BASE.$tenant.'/v2.0'],
            OidcFlavor::Google => [self::GOOGLE_ISSUER, self::GOOGLE_ISSUER],
            OidcFlavor::Generic => self::generic($config),
        };

        return new self(
            $flavor,
            $baseUrl,
            $issuer,
            $tenant,
            self::string($config, 'client_id') ?? throw self::invalid('client_id is required'),
            self::scopes($config['scopes'] ?? null),
            self::string($config, 'groups_claim') ?? 'groups',
            $flavor === OidcFlavor::Generic && (filter_var($config['trust_unverified_email'] ?? false, FILTER_VALIDATE_BOOLEAN)
                || filter_var($config['allow_unverified_email'] ?? false, FILTER_VALIDATE_BOOLEAN)),
        );
    }

    public function discoveryUrl(): string
    {
        return rtrim($this->baseUrl, '/').'/.well-known/openid-configuration';
    }

    /**
     * Additional Socialite config for the provider class (SocialiteProviders\OpenIDConnect\Provider keys).
     *
     * @return array<string, mixed>
     */
    public function providerConfig(): array
    {
        return array_filter([
            'base_url' => $this->baseUrl,
            'issuer' => $this->issuer,
            'tenant' => $this->tenant,
            'scopes' => $this->scopes,
            'use_nonce' => true,
            'verify_jwt' => true,
            'clock_skew' => config()->integer('sso.clock_skew', 60),
            'cache_ttl' => config()->integer('sso.cache_ttl', 3600),
            'http_timeout' => config()->integer('sso.http.timeout', 10),
            'http_connect_timeout' => config()->integer('sso.http.connect_timeout', 5),
            'email_claims' => $this->flavor === OidcFlavor::Entra ? null : ['email'],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: string}
     */
    private static function generic(array $config): array
    {
        $issuer = self::string($config, 'issuer') ?? throw self::invalid('issuer is required');
        $base = self::string($config, 'discovery_url') ?? $issuer;
        $base = (string) preg_replace('#/\.well-known/openid-configuration$#', '', rtrim($base, '/'));

        return [$base, $issuer];
    }

    /** @param  array<string, mixed>  $config */
    private static function tenant(array $config): string
    {
        $tenant = self::string($config, 'tenant');
        if ($tenant === null || preg_match(self::GUID, $tenant) !== 1) {
            throw self::invalid('Entra requires the directory (tenant) GUID');
        }

        return strtolower($tenant);
    }

    /** @return list<string> */
    private static function scopes(mixed $scopes): array
    {
        $list = is_array($scopes) ? $scopes : preg_split('/[\s,]+/', is_string($scopes) ? $scopes : '', -1, PREG_SPLIT_NO_EMPTY);
        $list = array_values(array_filter(array_map(static fn (mixed $s): string => is_string($s) ? trim($s) : '', $list ?: []), static fn (string $s): bool => $s !== ''));

        return $list === [] ? ['openid', 'email', 'profile'] : array_values(array_unique(['openid', ...$list]));
    }

    /** @param  array<string, mixed>  $config */
    private static function string(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function invalid(string $message): InvalidArgumentException
    {
        return new InvalidArgumentException(self::CONFIG_ERROR.' '.$message.'.');
    }
}
