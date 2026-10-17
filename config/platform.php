<?php

declare(strict_types=1);

/* The /platform operator panel (ADR 0041). */
return [
    'impersonation' => [
        'minutes' => (int) env('PLATFORM_IMPERSONATION_MINUTES', 30),
    ],
    'session_hours' => (int) env('PLATFORM_SESSION_HOURS', 12),
];
