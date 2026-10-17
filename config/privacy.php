<?php

declare(strict_types=1);

/* GDPR tooling (BUILD_PROMPT §11 Privacy, ADR 0039). */
return [
    'retention' => [
        // Minimum retention (months) for forms under a legal hold, e.g. invoices under German law.
        'legal_hold_min_months' => (int) env('PRIVACY_LEGAL_HOLD_MIN_MONTHS', 120),
        'batch' => (int) env('PRIVACY_RETENTION_BATCH', 500),
    ],
    'exports' => [
        'keep_days' => (int) env('PRIVACY_EXPORT_KEEP_DAYS', 7),
        'disk' => env('PRIVACY_EXPORT_DISK', 'local'),
        'per_hour' => (int) env('PRIVACY_EXPORTS_PER_HOUR', 10),
    ],
];
