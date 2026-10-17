<?php

declare(strict_types=1);

/*
 * SSO per organization (BUILD_PROMPT §8.7, ADR 0036).
 */
return [
    // Allow http and private/loopback IdP hosts (Keycloak in Docker). Development and CI only.
    'allow_insecure_idp' => (bool) env('SSO_ALLOW_INSECURE_IDP', false),

    // Domain verification: TXT `<txt_prefix>.<domain>` = `form-schema-app-verification=<token>`.
    'dns' => [
        // dns: dns_get_record (production); config: the records below (development and CI).
        'driver' => env('SSO_DNS_DRIVER', 'dns'),
        // Config driver only: TXT name => list of values, e.g.
        // {"_form-schema-app-verification.example.com": ["form-schema-app-verification=<token>"]}
        'records' => json_decode((string) env('SSO_DNS_RECORDS', '{}'), true) ?: [],
        'txt_prefix' => '_form-schema-app-verification',
    ],

    // sso:recheck-domains unverifies a domain after this many misses in a row.
    'recheck' => [
        'failures_before_unverify' => 3,
    ],

    // Outbound calls to IdPs (discovery, JWKS, token, SAML metadata), in seconds.
    'http' => [
        'timeout' => 10,
        'connect_timeout' => 5,
    ],

    // Discovery documents, JWKS and SAML metadata cache (seconds).
    'cache_ttl' => 3600,

    // Allowed clock skew for token and assertion timestamps (seconds).
    'clock_skew' => 60,

    // sso_misconfigured goes out at most once per provider and reason in this window (seconds, 6 h).
    'notify_every' => 21600,

    // Lifetime of a login flow: state/nonce/PKCE in the session, SAML RelayState and ACS tickets (seconds).
    'flow_ttl' => 600,

    // SAML SP signing/decryption key pair (PEM strings).
    'saml' => [
        'sp_certificate' => env('SSO_SAML_SP_CERTIFICATE'),
        'sp_private_key' => env('SSO_SAML_SP_PRIVATE_KEY'),
    ],
];
