<?php

declare(strict_types=1);

/*
 * Reverse proxies whose X-Forwarded-* headers are trusted (ADR 0040). Comma-separated IPs/CIDRs, or `*`
 * when the app is reachable only through the proxy. Empty: nothing is trusted.
 */
return [
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
