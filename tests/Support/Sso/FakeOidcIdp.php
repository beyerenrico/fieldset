<?php

declare(strict_types=1);

namespace Tests\Support\Sso;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Sso\Contracts\SsoMisconfigurationReporter;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Login\SsoStartLink;
use App\Domain\Identity\Sso\Providers\SsrfGuardedHttpClient;
use App\Domain\Identity\Tenancy\TenantContext;
use ArrayObject;
use Closure;
use Firebase\JWT\JWT;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\Feature\Workflows\FakeHostResolver;

/**
 * A fake OpenID provider behind the SSO HTTP client's transport (the SSRF guard still runs in front of it):
 * discovery, JWKS and the token endpoint, with id_tokens signed by RSA keys generated once per test run
 * (firebase/php-jwt). The token endpoint enforces one-time codes and PKCE S256 like a real OP.
 *
 * Usage: bind(), start a login, then authorize($redirectLocation, $claims) returns the callback URL.
 */
final class FakeOidcIdp
{
    public const string CLIENT_ID = 'form-schema-app';

    public const string CLIENT_SECRET = 'fake-client-secret';

    /** @var array<string, OpenSSLAsymmetricKey> */
    private static array $keys = [];

    /** @var list<array{method: string, url: string}> */
    public array $requests = [];

    /** @var array<string, array{status: int, json: array<string, mixed>|null}> path suffix => forced answer */
    private array $failures = [];

    /** @var array<string, array{claims: array<string, mixed>, challenge: string, key: string, kid: string}> */
    private array $codes = [];

    /** @var array<string, array<string, mixed>> access token => userinfo claims */
    private array $userinfo = [];

    /** @var list<array{key: string, kid: string}> published JWKS */
    private array $published = [['key' => 'main', 'kid' => 'main-1']];

    /** @var array{key: string, kid: string} */
    private array $signer = ['key' => 'main', 'kid' => 'main-1'];

    /** @var array<string, string>|null */
    public ?array $lastAuthorization = null;

    public function __construct(public readonly string $issuer) {}

    /** @param  array<string, list<string>>  $hosts  fake DNS for the SSRF guard */
    public static function bind(string $issuer = 'https://idp.example.test/realms/acme', array $hosts = []): self
    {
        $idp = new self($issuer);
        $resolver = FakeHostResolver::bind($hosts);
        app()->instance(SsrfGuardedHttpClient::class, new SsrfGuardedHttpClient($resolver)->withHandler($idp->handler()));

        return $idp;
    }

    /**
     * An active OIDC provider of the organization for this IdP (JIT on), created inside its tenant.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $config
     */
    public function provider(Organization $organization, array $attributes = [], array $config = []): IdentityProvider
    {
        return app(TenantContext::class)->run($organization, fn (): IdentityProvider => IdentityProvider::factory()->active()->create([
            'config' => ['flavor' => 'generic', 'issuer' => $this->issuer, 'client_id' => self::CLIENT_ID, 'scopes' => ['openid', 'email', 'profile'], ...$config],
            'client_secret' => self::CLIENT_SECRET,
            'jit_enabled' => true,
            ...$attributes,
        ]));
    }

    /** A domain claim of the organization, verified unless told otherwise. */
    public static function domain(Organization $organization, string $domain, bool $verified = true): SsoDomain
    {
        return app(TenantContext::class)->run($organization, fn (): SsoDomain => $verified
            ? SsoDomain::factory()->verified()->create(['domain' => $domain])
            : SsoDomain::factory()->create(['domain' => $domain]));
    }

    /**
     * A full login: redirect, the user's visit at the IdP, then the callback.
     *
     * @param  array<string, mixed>  $claims
     * @param  array{key?: string, kid?: string, state?: string}  $options
     */
    public function login(IdentityProvider $provider, array $claims, SsoClient $client = SsoClient::App, array $options = []): TestResponse
    {
        $redirect = test()->get(self::startUrl($provider, $client))->assertRedirect();

        return test()->get($this->authorize((string) $redirect->headers->get('Location'), $claims, $options));
    }

    /** A login start URL with this session's start token, as discovery hands it out (SsoStartLink). */
    public static function startUrl(IdentityProvider $provider, SsoClient $client = SsoClient::App, ?string $loginHint = null, ?int $invitation = null): string
    {
        return app(SsoStartLink::class)->url($provider, $client, $loginHint, $invitation);
    }

    /**
     * Binds a misconfiguration reporter that records each report's reason; returns the live list.
     *
     * @return ArrayObject<int, string>
     */
    public static function spyReporter(): ArrayObject
    {
        /** @var ArrayObject<int, string> $reports */
        $reports = new ArrayObject;
        app()->instance(SsoMisconfigurationReporter::class, new readonly class($reports) implements SsoMisconfigurationReporter
        {
            /** @param  ArrayObject<int, string>  $reports */
            public function __construct(private ArrayObject $reports) {}

            public function report(IdentityProvider $p, string $reason, string $detail): void
            {
                $this->reports->append($reason);
            }
        });

        return $reports;
    }

    public function handler(): Closure
    {
        return fn (RequestInterface $request, array $options): PromiseInterface => Create::promiseFor($this->respond($request));
    }

    /** @param  array<string, mixed>|null  $json  a JSON error body instead of plain text */
    public function failWith(string $pathSuffix, int $status, ?array $json = null): void
    {
        $this->failures[$pathSuffix] = ['status' => $status, 'json' => $json];
    }

    /** Publishes a new key (kid) and signs future tokens with it; the old key leaves the JWKS. */
    public function rotateKeys(): void
    {
        $this->published = [['key' => 'rotated', 'kid' => 'rotated-2']];
        $this->signer = ['key' => 'rotated', 'kid' => 'rotated-2'];
    }

    /**
     * Plays the user's visit to the authorization endpoint and returns the redirect back to the RP.
     *
     * @param  array<string, mixed>  $claims  id_token claims (merged over iss/aud/sub/exp/iat/nonce defaults)
     * @param  array{key?: string, kid?: string, state?: string}  $options  key "foreign" signs with an unpublished key
     */
    public function authorize(string $authorizationUrl, array $claims = [], array $options = []): string
    {
        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
        $query = array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $query);
        $this->lastAuthorization = $query;
        $code = Str::random(32);
        $this->codes[$code] = [
            'claims' => [...$this->defaultClaims($query['nonce'] ?? ''), ...$claims],
            'challenge' => $query['code_challenge'] ?? '',
            'key' => $options['key'] ?? $this->signer['key'],
            'kid' => $options['kid'] ?? $this->signer['kid'],
        ];

        return $query['redirect_uri'].'?'.http_build_query(['code' => $code, 'state' => $options['state'] ?? ($query['state'] ?? '')]);
    }

    /** An IdP-side error redirect (e.g. access_denied). */
    public function deny(string $authorizationUrl, string $error = 'access_denied'): string
    {
        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

        return $query['redirect_uri'].'?'.http_build_query(['error' => $error, 'state' => $query['state'] ?? '']);
    }

    public function requestCount(string $pathSuffix): int
    {
        return count(array_filter($this->requests, static fn (array $r): bool => str_ends_with((string) parse_url($r['url'], PHP_URL_PATH), $pathSuffix)));
    }

    /** @return array<string, mixed> */
    private function defaultClaims(string $nonce): array
    {
        return [
            'iss' => $this->issuer, 'aud' => self::CLIENT_ID, 'sub' => 'subject-'.Str::random(8),
            'iat' => time(), 'exp' => time() + 300, 'nonce' => $nonce, 'email_verified' => true,
        ];
    }

    private function respond(RequestInterface $request): Response
    {
        $url = (string) $request->getUri();
        $path = $request->getUri()->getPath();
        $this->requests[] = ['method' => $request->getMethod(), 'url' => $url];

        foreach ($this->failures as $suffix => $failure) {
            if (str_ends_with($path, $suffix)) {
                return $failure['json'] === null ? new Response($failure['status'], [], 'forced failure') : $this->json($failure['json'], $failure['status']);
            }
        }

        return match (true) {
            str_ends_with($path, '/.well-known/openid-configuration') => $this->json($this->discovery()),
            str_ends_with($path, '/jwks') => $this->json(['keys' => array_map(fn (array $k): array => $this->jwk($k['key'], $k['kid']), $this->published)]),
            str_ends_with($path, '/token') && $request->getMethod() === 'POST' => $this->token($request),
            str_ends_with($path, '/userinfo') => $this->userinfo($request),
            default => new Response(404, [], 'not found'),
        };
    }

    /** @return array<string, mixed> */
    private function discovery(): array
    {
        return [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->issuer.'/protocol/openid-connect/auth',
            'token_endpoint' => $this->issuer.'/token',
            'userinfo_endpoint' => $this->issuer.'/userinfo',
            'jwks_uri' => $this->issuer.'/jwks',
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
        ];
    }

    private function token(RequestInterface $request): Response
    {
        parse_str((string) $request->getBody(), $form);
        $code = is_string($form['code'] ?? null) ? $form['code'] : '';
        $grant = $this->codes[$code] ?? null;
        unset($this->codes[$code]);

        if ($grant === null) {
            return $this->json(['error' => 'invalid_grant'], 400);
        }
        $verifier = is_string($form['code_verifier'] ?? null) ? $form['code_verifier'] : '';
        if (! hash_equals($grant['challenge'], rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))) {
            return $this->json(['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed'], 400);
        }

        $accessToken = Str::random(40);
        $this->userinfo[$accessToken] = array_diff_key($grant['claims'], array_flip(['iss', 'aud', 'iat', 'exp', 'nonce']));

        return $this->json([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 300,
            'id_token' => JWT::encode($grant['claims'], $this->privateKeyPem($grant['key']), 'RS256', $grant['kid']),
        ]);
    }

    private function userinfo(RequestInterface $request): Response
    {
        $claims = $this->userinfo[Str::after($request->getHeaderLine('Authorization'), 'Bearer ')] ?? null;

        return $claims === null ? $this->json(['error' => 'invalid_token'], 401) : $this->json($claims);
    }

    /** @param  array<string, mixed>  $data */
    private function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    private function key(string $name): OpenSSLAsymmetricKey
    {
        return self::$keys[$name] ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA])
            ?: throw new RuntimeException('Cannot generate an RSA key.');
    }

    private function privateKeyPem(string $name): string
    {
        openssl_pkey_export($this->key($name), $pem);

        return (string) $pem;
    }

    /** @return array<string, string> */
    private function jwk(string $name, string $kid): array
    {
        $details = openssl_pkey_get_details($this->key($name)) ?: throw new RuntimeException('No key details.');
        $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid, 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])];
    }
}
