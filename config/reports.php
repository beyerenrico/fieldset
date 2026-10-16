<?php

declare(strict_types=1);

/*
 * Reports (BUILD_PROMPT §8.4, ADR 0033): submission explorer, queued exports and saved reports.
 */
return [

    'exports' => [
        // Private disk for export files (exports/{organization}/{export}/…); downloads go through the app.
        'disk' => env('REPORTS_EXPORT_DISK', 'local'),

        'queue' => env('REPORTS_EXPORT_QUEUE', 'default'),

        // Seconds an export job may run. Keep it below the queue connection's retry_after (90 s by default),
        // or a long export is handed to a second worker while the first still writes it.
        'timeout' => (int) env('REPORTS_EXPORT_TIMEOUT', 85),

        // Files and rows are pruned this many days after the request; downloads answer 410 after expiry.
        'keep_days' => (int) env('REPORTS_EXPORT_KEEP_DAYS', 7),

        // Submissions read per query while writing (memory stays flat for any export size).
        'chunk_size' => 500,

        // Most rows a bulk action may select (larger exports use "Export all" with the filters).
        'max_selected' => 10000,
    ],

    'pdf_zip' => [
        // The ZIP stops taking PDFs at this size or count; the notification says how many were left out.
        'max_bytes' => (int) env('REPORTS_PDF_ZIP_MAX_BYTES', 200 * 1024 * 1024),
        'max_files' => (int) env('REPORTS_PDF_ZIP_MAX_FILES', 2000),
    ],

    'schedule' => [
        // Local hour (organization time zone) at which weekly (Mondays) and monthly (1st) emails go out.
        'hour' => 7,
    ],
];
