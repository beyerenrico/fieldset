<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Providers;

use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use GuzzleHttp\Exception\GuzzleException;
use SocialiteProviders\Manager\Exception\MissingConfigException;
use Throwable;

/**
 * Maps exceptions of the OIDC/SAML packages to an error code and, for provider faults, a misconfiguration
 * reason (ADR 0036, Consequences: classification depends on the packages' messages; the needles are pinned
 * by tests/Unit/Sso/FailureClassifierTest against socialiteproviders/openidconnect 1.0.1, saml2 4.10 and
 * LightSAML 4.7.2). The first matching needle wins, so wrapped messages ("JWT: Verification failed - Unable
 * to fetch JWKS: …") are listed before their wrappers.
 */
final class FailureClassifier
{
    /** @var list<array{0: string, 1: SsoRefusal, 2: string|null}> needle, code, misconfiguration reason */
    private const array RULES = [
        [SsrfBlockedException::MARKER, SsoRefusal::ProviderUnavailable, 'blocked_host'],
        [OidcSettings::CONFIG_ERROR, SsoRefusal::ProviderUnavailable, 'config'],
        ['OIDC: base_url', SsoRefusal::ProviderUnavailable, 'config'],
        ['Unable to get the OIDC configuration', SsoRefusal::ProviderUnavailable, 'discovery'],
        ['JWKS URI not found', SsoRefusal::ProviderUnavailable, 'discovery'],
        ['Unable to fetch JWKS', SsoRefusal::ProviderUnavailable, 'jwks'],
        ['invalid_client', SsoRefusal::SsoFailed, 'invalid_client'],
        ['unauthorized_client', SsoRefusal::SsoFailed, 'invalid_client'],
        ['JWT: Invalid issuer', SsoRefusal::SsoFailed, 'issuer'],
        [AppEntraProvider::TID_MISMATCH, SsoRefusal::SsoFailed, 'tenant'],
        ['JWT: Invalid audience', SsoRefusal::SsoFailed, 'audience'],
        ['JWT: Invalid authorized party', SsoRefusal::SsoFailed, 'audience'],
        ['JWT: Multiple audiences', SsoRefusal::SsoFailed, 'audience'],
        ['JWT: Disallowed signing algorithm', SsoRefusal::SsoFailed, 'signature'],
        // firebase/php-jwt time checks, wrapped in "JWT: Verification failed - …": not a provider fault.
        ['Expired token', SsoRefusal::SsoFailed, null],
        ['Cannot handle token with', SsoRefusal::SsoFailed, null],
        ['JWT: Verification failed', SsoRefusal::SsoFailed, 'signature'],
        ['IdP returned error - access_denied', SsoRefusal::SsoDenied, null],
        ['The signature of the assertion could not be verified', SsoRefusal::SsoFailed, 'saml_signature'],
        ['The received assertion had no available signature', SsoRefusal::SsoFailed, 'saml_signature'],
        ['assertion issuer entity id did not match', SsoRefusal::SsoFailed, 'saml_issuer'],
        ['could not be found in the metadata', SsoRefusal::ProviderUnavailable, 'metadata'],
    ];

    private const int DETAIL_LIMIT = 500;

    public function classify(Throwable $e, IdentityProviderType $type): SsoFailure
    {
        $message = $e->getMessage();
        $detail = mb_substr($e::class.': '.$message, 0, self::DETAIL_LIMIT);

        foreach (self::RULES as [$needle, $code, $reason]) {
            if (str_contains($message, $needle)) {
                return new SsoFailure($code, $reason, $detail);
            }
        }

        return match (true) {
            $e instanceof MissingConfigException => new SsoFailure(SsoRefusal::ProviderUnavailable, 'config', $detail),
            // Token endpoint (OIDC) or metadata URL (SAML) unreachable or answering an HTTP error.
            $e instanceof GuzzleException => new SsoFailure(
                SsoRefusal::ProviderUnavailable,
                $type === IdentityProviderType::Saml ? 'metadata' : 'token_endpoint',
                $detail,
            ),
            default => new SsoFailure(SsoRefusal::SsoFailed, null, $detail),
        };
    }
}
