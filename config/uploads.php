<?php

declare(strict_types=1);

/* Optional malware scanning of uploads through clamd (ADR 0040). Disabled by default. */
return [
    'clamav' => [
        'enabled' => (bool) env('UPLOADS_CLAMAV_ENABLED', false),
        'host' => env('UPLOADS_CLAMAV_HOST', 'clamav'),
        'port' => (int) env('UPLOADS_CLAMAV_PORT', 3310),
        'timeout' => (float) env('UPLOADS_CLAMAV_TIMEOUT', 10),
        // true: accept uploads when clamd gives no verdict (default: refuse with 503).
        'fail_open' => (bool) env('UPLOADS_CLAMAV_FAIL_OPEN', false),
    ],
];
