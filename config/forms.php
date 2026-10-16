<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | qpdf (fillable PDF import, ADR 0024)
    |--------------------------------------------------------------------------
    |
    | Path to the qpdf binary used to read AcroForm fields. A relative path is resolved against the
    | project root. Hosts without qpdf can point this at scripts/qpdf, which runs qpdf from Docker.
    |
    */

    'qpdf_binary' => env('QPDF_BINARY', 'qpdf'),

    'pdf_import' => [
        // Upload limits (BUILD_PROMPT §8.13): the file is untrusted.
        'max_bytes' => (int) env('PDF_IMPORT_MAX_BYTES', 20 * 1024 * 1024),
        'max_pages' => (int) env('PDF_IMPORT_MAX_PAGES', 50),
        // Seconds per qpdf call, and the largest qpdf JSON output read into memory.
        'timeout' => (int) env('PDF_IMPORT_TIMEOUT', 60),
        'max_output_bytes' => 64 * 1024 * 1024,
        // Private disk and directory for uploaded PDFs; files are deleted after the import job.
        'disk' => 'local',
        'directory' => 'pdf-imports',
        // Elements imported below this confidence are highlighted in the builder.
        'low_confidence' => 0.6,
    ],

];
