<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Renditions (BUILD_PROMPT §8.5, ADR 0028)
    |--------------------------------------------------------------------------
    |
    | PDFs are stored on this (private) disk under renditions/{org}/{submission}/{uuid}.pdf.
    | `render_on_submit` queues GenerateRendition for every submit. It defaults to off in the test
    | environment so unrelated tests never call Gotenberg; document tests switch it on.
    |
    */

    'disk' => env('DOCUMENTS_DISK', 'local'),

    'render_on_submit' => (bool) env('DOCUMENTS_RENDER_ON_SUBMIT', env('APP_ENV') !== 'testing'),

    'queue' => env('DOCUMENTS_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Render request budget
    |--------------------------------------------------------------------------
    |
    | Every asset is inlined (the renderer has no network), so the request to Gotenberg carries all
    | photos, the logo and the fonts. Larger documents fail with a clear error instead of hitting
    | Gotenberg's --api-body-limit (30 MB in compose.yaml). Photos are downscaled for print first.
    |
    */

    'max_request_bytes' => (int) env('DOCUMENTS_MAX_REQUEST_BYTES', 25 * 1024 * 1024),

    'photo_max_edge' => (int) env('DOCUMENTS_PHOTO_MAX_EDGE', 1600),

    /*
    |--------------------------------------------------------------------------
    | Download links
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/submissions/{uuid}/document answers with a signed URL valid for this many minutes.
    |
    */

    'link_ttl_minutes' => (int) env('DOCUMENTS_LINK_TTL_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Organization logo (branding page)
    |--------------------------------------------------------------------------
    */

    'logo_max_kb' => (int) env('DOCUMENTS_LOGO_MAX_KB', 2048),

    'logo_max_side' => (int) env('DOCUMENTS_LOGO_MAX_SIDE', 4000),

    'logo_max_pixels' => (int) env('DOCUMENTS_LOGO_MAX_PIXELS', 16_000_000),

];
