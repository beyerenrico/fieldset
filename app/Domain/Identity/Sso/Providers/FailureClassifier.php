<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Providers;

use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use App\Domain\Identity\Sso\Login\SamlProfileFactory;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ResponseException;
use SocialiteProviders\Manager\Exception\MissingConfigException;
use Throwable;

/**
 * Maps exceptions of the OIDC/SAML packages to an error code, a fixed cause and, for provider faults, a
 * misconfiguration reason (ADR 0036, Consequences: classification depends on the packages' messages; the
 * needles are pinned by tests/Unit/Sso/FailureClassifierTest against socialiteproviders/openidconnect 1.0.1,
 * saml2 4.10 and LightSAML 4.7.2). The first matching needle wins, so wrapped messages ("JWT: Verification
 * failed - Unable to fetch JWKS: …") are listed before their wrappers.
 *
 * Only back-channel evidence is a provider fault: what this server fetched itself (discovery, JWKS, the token
 * endpoint's answer, SAML metadata) and the configuration. Anything the browser brought — the OIDC callback's
 * `error`/`error_description`, every part of a posted SAML response (`$frontChannel`) and a token endpoint's
 * refusal of a code the browser supplied — can be forged by anyone who starts a flow, so it never sets
 * status=error or notifies, and its detail is a fixed text instead of the (attacker-controlled) message.
 */
final class FailureClassifier
{
    /** RFC 6749 §4.1.2.1 and OIDC Core §3.1.2.6 error codes, the only callback values the audit keeps. */
    public const array IDP_ERRORS = [
        'invalid_request', 'unauthorized_client', 'access_denied', 'unsupported_response_type', 'invalid_scope',
        'server_error', 'temporarily_unavailable', 'interaction_required', 'login_required',
        'account_selection_required', 'consent_required', 'invalid_request_uri', 'invalid_request_object',
        'request_not_supported', 'request_uri_not_supported', 'registration_not_supported',
    ];

    /** @var list<array{0: string, 1: SsoRefusal, 2: string, 3: bool}> needle, code, cause, provider fault */
    private const array RULES = [
        [SsrfBlockedException::MARKER, SsoRefusal::ProviderUnavailable, 'blocked_host', true],
        [OidcSettings::CONFIG_ERROR, SsoRefusal::ProviderUnavailable, 'config', true],
        ['OIDC: base_url', SsoRefusal::ProviderUnavailable, 'config', true],
        ['Unable to get the OIDC configuration', SsoRefusal::ProviderUnavailable, 'discovery', true],
        ['JWKS URI not found', SsoRefusal::ProviderUnavailable, 'discovery', true],
        ['Unable to fetch JWKS', SsoRefusal::ProviderUnavailable, 'jwks', true],
        ['JWT: Invalid issuer', SsoRefusal::SsoFailed, 'issuer', true],
        [AppEntraProvider::TID_MISMATCH, SsoRefusal::SsoFailed, 'tenant', true],
        ['JWT: Invalid audience', SsoRefusal::SsoFailed, 'audience', true],
        ['JWT: Invalid authorized party', SsoRefusal::SsoFailed, 'audience', true],
        ['JWT: Multiple audiences', SsoRefusal::SsoFailed, 'audience', true],
        ['JWT: Disallowed signing algorithm', SsoRefusal::SsoFailed, 'signature', true],
        // firebase/php-jwt time checks, wrapped in "JWT: Verification failed - …": not a provider fault.
        ['Expired token', SsoRefusal::SsoFailed, 'token_time', false],
        ['Cannot handle token with', SsoRefusal::SsoFailed, 'token_time', false],
        ['JWT: Verification failed', SsoRefusal::SsoFailed, 'signature', true],
        ['The signature of the assertion could not be verified', SsoRefusal::SsoFailed, 'saml_signature', true],
        ['The received assertion had no available signature', SsoRefusal::SsoFailed, 'saml_signature', true],
        ['assertion issuer entity id did not match', SsoRefusal::SsoFailed, 'saml_issuer', true],
        ['could not be found in the metadata', SsoRefusal::ProviderUnavailable, 'metadata', true],
        [AppSaml2Provider::UNSUCCESSFUL_STATUS, SsoRefusal::SsoFailed, 'saml_status', false],
        [AppSaml2Provider::DTD_REFUSED, SsoRefusal::SsoFailed, 'saml_dtd', false],
        [AppSaml2Provider::REPLAYED, SsoRefusal::SsoFailed, 'saml_replay', false],
        [AppSaml2Provider::AUDIENCE_MISMATCH, SsoRefusal::SsoFailed, 'saml_audience', false],
        [AppSaml2Provider::NOT_IN_RESPONSE, SsoRefusal::SsoFailed, 'saml_in_response_to', false],
        [AppSaml2Provider::WRONG_RECIPIENT, SsoRefusal::SsoFailed, 'saml_recipient', false],
        // Signed, validated assertions (back channel by trust): the IdP's NameID/subject configuration.
        [SamlProfileFactory::NAME_ID_NOT_PERSISTENT, SsoRefusal::SsoFailed, 'saml_name_id', true],
        [SamlProfileFactory::NO_SUBJECT, SsoRefusal::SsoFailed, 'saml_subject', true],
    ];

    /** A token endpoint that rejects our client credentials (Guzzle message of a 4xx with a JSON error body). */
    private const string INVALID_CLIENT_BODY = '/resulted in a `4\d\d [^`]*` response:\s*\{\s*"error"\s*:\s*"(invalid_client|unauthorized_client)"/';

    private const string CALLBACK = 'Callback: ';

    private const string CALLBACK_IDP_ERROR = 'Callback: IdP returned error - ';

    private const string TOKEN_ERROR = 'Token endpoint: returned error - ';

    private const array CLIENT_ERRORS = ['invalid_client', 'unauthorized_client'];

    private const string TOKEN_REJECTED = 'token_rejected';

    private const int DETAIL_LIMIT = 500;

    /** @param  bool  $frontChannel  the exception came out of input the browser posted (a SAML response) */
    public function classify(Throwable $e, IdentityProviderType $type, bool $frontChannel = false): SsoFailure
    {
        $message = $e->getMessage();
        if (str_starts_with($message, self::CALLBACK)) {
            return $this->callback($message);
        }

        [$code, $cause, $fault, $needle] = $this->match($e, $message, $type);
        if ($frontChannel) {
            return new SsoFailure($code, null, $needle ?? 'unclassified: '.class_basename($e), $cause);
        }
        if ($cause === self::TOKEN_REJECTED) {
            // The IdP's answer to a code the browser brought may quote that code.
            return new SsoFailure($code, null, $cause, $cause);
        }

        return new SsoFailure($code, $fault ? $cause : null, $this->detail($e), $cause);
    }

    /** The audit cause of an IdP error redirect: `idp_error:<code>` for a standard code, else `idp_error`. */
    public static function idpErrorCause(string $error): string
    {
        return in_array($error, self::IDP_ERRORS, true) ? 'idp_error:'.$error : 'idp_error';
    }

    /**
     * The OIDC callback's own checks and the IdP's error redirect (front channel).
     */
    private function callback(string $message): SsoFailure
    {
        if (! str_starts_with($message, self::CALLBACK_IDP_ERROR)) {
            return new SsoFailure(SsoRefusal::SsoFailed, null, 'callback', 'callback');
        }
        $error = substr($message, strlen(self::CALLBACK_IDP_ERROR));
        $cause = self::idpErrorCause($error);

        return new SsoFailure($error === 'access_denied' ? SsoRefusal::SsoDenied : SsoRefusal::SsoFailed, null, $cause, $cause);
    }

    /**
     * @return array{0: SsoRefusal, 1: string, 2: bool, 3: string|null} code, cause, provider fault, fixed needle
     */
    private function match(Throwable $e, string $message, IdentityProviderType $type): array
    {
        if ($e instanceof GuzzleException) {
            // Its message quotes the response body, which may echo browser input: classified by status and body.
            return $this->byType($e, $type);
        }
        foreach (self::RULES as [$needle, $code, $cause, $fault]) {
            if (str_contains($message, $needle)) {
                return [$code, $cause, $fault, $needle];
            }
        }
        if (str_starts_with($message, self::TOKEN_ERROR)) {
            $client = in_array(substr($message, strlen(self::TOKEN_ERROR)), self::CLIENT_ERRORS, true);

            return [SsoRefusal::SsoFailed, $client ? 'invalid_client' : self::TOKEN_REJECTED, $client, null];
        }
        if (preg_match(self::INVALID_CLIENT_BODY, $message) === 1) {
            return [SsoRefusal::SsoFailed, 'invalid_client', true, null];
        }

        return $this->byType($e, $type);
    }

    /**
     * @return array{0: SsoRefusal, 1: string, 2: bool, 3: string|null}
     */
    private function byType(Throwable $e, IdentityProviderType $type): array
    {
        if ($e instanceof MissingConfigException) {
            return [SsoRefusal::ProviderUnavailable, 'config', true, null];
        }
        if (! $e instanceof GuzzleException) {
            return [SsoRefusal::SsoFailed, 'unclassified', false, null];
        }
        // SAML: only the metadata URL is fetched. OIDC: a 4xx of the token endpoint answers the code the browser
        // brought (invalid_grant), unless it rejects our client; a connection failure or 5xx is the provider's.
        if ($type === IdentityProviderType::Saml) {
            return [SsoRefusal::ProviderUnavailable, 'metadata', true, null];
        }
        $status = $e instanceof ResponseException ? $e->getResponse()->getStatusCode() : null;
        if ($status !== null && $status >= 400 && $status < 500) {
            $client = $this->isClientError($e);

            return [SsoRefusal::SsoFailed, $client ? 'invalid_client' : self::TOKEN_REJECTED, $client, null];
        }

        return [SsoRefusal::ProviderUnavailable, 'token_endpoint', true, null];
    }

    private function isClientError(GuzzleException $e): bool
    {
        $body = $e instanceof ResponseException ? (string) $e->getResponse()->getBody() : '';
        $error = json_decode($body, true);

        return is_array($error) && in_array($error['error'] ?? null, self::CLIENT_ERRORS, true);
    }

    /** Server-side detail of a back-channel failure: exception class and message without control characters. */
    private function detail(Throwable $e): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $e::class.': '.$e->getMessage());

        return mb_substr(mb_scrub($text, 'UTF-8'), 0, self::DETAIL_LIMIT);
    }
}
