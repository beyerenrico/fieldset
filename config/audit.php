<?php

declare(strict_types=1);

/* Audit log (BUILD_PROMPT §8.11, ADR 0038). */
return [
    // Entries older than this are pruned daily (audit:prune); the database refuses anything younger than 30.
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),
    'export' => [
        // Larger CSV exports are queued and delivered as a signed link.
        'sync_max_rows' => (int) env('AUDIT_EXPORT_SYNC_MAX_ROWS', 2000),
    ],
];
