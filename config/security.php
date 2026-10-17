<?php

declare(strict_types=1);

/* HTTP security headers and host allow-list (ADR 0040). */
return [
    'hsts' => [
        // null: on whenever the request is HTTPS (proxy-aware) or APP_URL is https.
        'enabled' => env('SECURITY_HSTS_ENABLED'),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', false),
        'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
    ],
    // Extra host names (comma-separated) besides the APP_URL host; others get 400 outside local/testing.
    'trusted_hosts' => env('TRUSTED_HOSTS', ''),
];
