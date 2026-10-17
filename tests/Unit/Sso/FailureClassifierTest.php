<?php

declare(strict_types=1);

use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use App\Domain\Identity\Sso\Providers\FailureClassifier;
use App\Domain\Identity\Sso\Providers\SsrfBlockedException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LightSaml\Error\LightSamlValidationException;
use SocialiteProviders\Manager\Exception\MissingConfigException;
use SocialiteProviders\Saml2\InvalidSignatureException;

/*
 * ADR 0036, Consequences: failure classification depends on the packages' exception messages. The first test
 * pins each needle to the installed sources (socialiteproviders/openidconnect 1.0.1, saml2 4.10,
 * firebase/php-jwt 7.2), so an upgrade that rewords a message fails here instead of silently downgrading a
 * provider fault to a plain sso_failed.
 */
it('finds every package needle in the installed sources', function (string $needle, string $file): void {
    expect((string) file_get_contents(base_path($file)))->toContain($needle);
})->with([
    ['Unable to get the OIDC configuration', 'vendor/socialiteproviders/openidconnect/Provider.php'],
    ['JWKS URI not found', 'vendor/socialiteproviders/openidconnect/Provider.php'],
    ['Unable to fetch JWKS', 'vendor/socialiteproviders/openidconnect/Provider.php'],
    // "JWT: Invalid issuer." is built as $context.': Invalid issuer.' with the context 'JWT'.
    ["validateIssuerClaim(\$payload, 'JWT')", 'vendor/socialiteproviders/openidconnect/Provider.php'],
    ["': Invalid issuer.'", 'vendor/socialiteproviders/openidconnect/Provider.php'],
    ['JWT: Invalid audience', 'vendor/socialiteproviders/openidconnect/Provider.php'],
    ['JWT: Invalid authorized party', 'vendor/socialiteproviders/openidconnect/Provider.php'],
    ['JWT: Multiple audiences', 'vendor/socialiteproviders/openidconnect/Provider.php'],
    ['JWT: Disallowed signing algorithm', 'vendor/socialiteproviders/openidconnect/Provider.php'],
    ['JWT: Verification failed', 'vendor/socialiteproviders/openidconnect/Provider.php'],
    ['OIDC: base_url', 'vendor/socialiteproviders/openidconnect/Provider.php'],
    ['Callback: IdP returned error - ', 'vendor/socialiteproviders/openidconnect/Provider.php'],
    ['Expired token', 'vendor/firebase/php-jwt/src/JWT.php'],
    ['Cannot handle token with', 'vendor/firebase/php-jwt/src/JWT.php'],
    ['The signature of the assertion could not be verified', 'vendor/socialiteproviders/saml2/Provider.php'],
    ['The received assertion had no available signature', 'vendor/socialiteproviders/saml2/Provider.php'],
    ['assertion issuer entity id did not match', 'vendor/socialiteproviders/saml2/Provider.php'],
    ['could not be found in the metadata', 'vendor/socialiteproviders/saml2/Provider.php'],
]);

it('classifies package failures', function (Throwable $e, SsoRefusal $code, ?string $misconfiguration): void {
    $failure = (new FailureClassifier)->classify($e, IdentityProviderType::Oidc);

    expect($failure->code)->toBe($code)->and($failure->misconfiguration)->toBe($misconfiguration);
    if (str_starts_with($e->getMessage(), 'Callback: ')) {
        // Front channel (the browser's callback query): a fixed detail, never the message.
        expect($failure->detail)->not->toContain($e->getMessage());
    } else {
        expect($failure->detail)->toContain($e->getMessage());
    }
})->with([
    'ssrf' => [SsrfBlockedException::for('10.0.0.1', 'not a public address'), SsoRefusal::ProviderUnavailable, 'blocked_host'],
    'discovery' => [new InvalidArgumentException('Unable to get the OIDC configuration from https://x/.well-known/openid-configuration: 503'), SsoRefusal::ProviderUnavailable, 'discovery'],
    'jwks wrapped' => [new InvalidArgumentException('JWT: Verification failed - Unable to fetch JWKS: Server error'), SsoRefusal::ProviderUnavailable, 'jwks'],
    'signature' => [new InvalidArgumentException('JWT: Verification failed - Signature verification failed'), SsoRefusal::SsoFailed, 'signature'],
    'expired' => [new InvalidArgumentException('JWT: Verification failed - Expired token'), SsoRefusal::SsoFailed, null],
    'issuer' => [new InvalidArgumentException('JWT: Invalid issuer.'), SsoRefusal::SsoFailed, 'issuer'],
    'audience' => [new InvalidArgumentException('JWT: Invalid audience.'), SsoRefusal::SsoFailed, 'audience'],
    'invalid_client' => [new RuntimeException('Client error: `POST https://x/token` resulted in a `401 Unauthorized` response: {"error":"invalid_client"}'), SsoRefusal::SsoFailed, 'invalid_client'],
    'access denied' => [new InvalidArgumentException('Callback: IdP returned error - access_denied'), SsoRefusal::SsoDenied, null],
    'other idp error' => [new InvalidArgumentException('Callback: IdP returned error - temporarily_unavailable'), SsoRefusal::SsoFailed, null],
    'state' => [new InvalidArgumentException('Callback: invalid state.'), SsoRefusal::SsoFailed, null],
    'nonce' => [new InvalidArgumentException('JWT: Contains an invalid nonce.'), SsoRefusal::SsoFailed, null],
    'token endpoint down' => [new ConnectException('cURL error 7', new Request('POST', 'https://x/token')), SsoRefusal::ProviderUnavailable, 'token_endpoint'],
    'missing config' => [new MissingConfigException('Either the "metadata" or "acs" config keys must be set'), SsoRefusal::ProviderUnavailable, 'config'],
    'saml signature' => [new InvalidSignatureException('The signature of the assertion could not be verified'), SsoRefusal::SsoFailed, 'saml_signature'],
    'anything else' => [new LogicException('boom'), SsoRefusal::SsoFailed, null],
]);

it('attributes an unreachable SAML metadata URL to the metadata', function (): void {
    $failure = (new FailureClassifier)->classify(new ConnectException('cURL error 6', new Request('GET', 'https://idp/metadata')), IdentityProviderType::Saml);

    expect($failure->misconfiguration)->toBe('metadata')->and($failure->code)->toBe(SsoRefusal::ProviderUnavailable);
});

it('keeps details short', function (): void {
    expect(mb_strlen((new FailureClassifier)->classify(new RuntimeException(str_repeat('x', 5000)), IdentityProviderType::Oidc)->detail))->toBe(500);
});

it('pins the token endpoint error prefix it relies on', function (): void {
    expect((string) file_get_contents(base_path('vendor/socialiteproviders/openidconnect/Provider.php')))
        ->toContain("'Token endpoint: returned error - '");
});

it('never treats front-channel input as a provider fault or keeps its text', function (Throwable $e, IdentityProviderType $type, bool $frontChannel, string $cause): void {
    $failure = (new FailureClassifier)->classify($e, $type, $frontChannel);

    expect($failure->misconfiguration)->toBeNull()
        ->and($failure->cause)->toBe($cause)
        ->and($failure->detail)->not->toContain('+1-555-0100');
})->with([
    'callback error with a description' => [new InvalidArgumentException('Callback: IdP returned error - invalid_client: call +1-555-0100'), IdentityProviderType::Oidc, false, 'idp_error'],
    'saml signature, posted' => [new InvalidSignatureException('The signature of the assertion could not be verified'), IdentityProviderType::Saml, true, 'saml_signature'],
    'saml free text, posted' => [new LightSamlValidationException('invalid_client Unable to fetch JWKS call +1-555-0100'), IdentityProviderType::Saml, true, 'jwks'],
    'saml unknown, posted' => [new RuntimeException('call +1-555-0100'), IdentityProviderType::Saml, true, 'unclassified'],
    'token endpoint refuses the code' => [new ClientException('Client error: `POST https://x/token` resulted in a `400 Bad Request` response: {"error":"invalid_grant","error_description":"Unable to fetch JWKS +1-555-0100"}', new Request('POST', 'https://x/token'), new Response(400, [], '{"error":"invalid_grant","error_description":"Unable to fetch JWKS +1-555-0100"}')), IdentityProviderType::Oidc, false, 'token_rejected'],
    'token endpoint 200 error' => [new InvalidArgumentException('Token endpoint: returned error - Code not valid'), IdentityProviderType::Oidc, false, 'token_rejected'],
]);

it('classifies a token endpoint that rejects our client as a provider fault', function (Throwable $e): void {
    expect((new FailureClassifier)->classify($e, IdentityProviderType::Oidc))
        ->misconfiguration->toBe('invalid_client')->cause->toBe('invalid_client');
})->with([
    'guzzle 401' => [new ClientException('Client error', new Request('POST', 'https://x/token'), new Response(401, [], '{"error":"invalid_client"}'))],
    '200 with an error body' => [new InvalidArgumentException('Token endpoint: returned error - unauthorized_client')],
]);

it('keeps back-channel details without control characters', function (): void {
    $failure = (new FailureClassifier)->classify(new InvalidArgumentException("Unable to get the OIDC configuration\r\nInjected: line"), IdentityProviderType::Oidc);

    expect($failure->detail)->toBe('InvalidArgumentException: Unable to get the OIDC configuration Injected: line')
        ->and($failure->misconfiguration)->toBe('discovery');
});
