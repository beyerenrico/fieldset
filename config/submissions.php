<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Request limits (Field App API, BUILD_PROMPT §9.2, §11)
    |--------------------------------------------------------------------------
    |
    | Largest non-multipart request body accepted on /api/* (draft upserts are the largest JSON bodies).
    | Checked before the body is decoded; larger bodies get 413 `payload_too_large`. Attachments are
    | multipart uploads with their own limits (UploadInspector).
    |
    */

    'max_payload_bytes' => (int) env('SUBMISSIONS_MAX_PAYLOAD_BYTES', 2 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Idempotency keys (POST /submissions/{uuid}/submit)
    |--------------------------------------------------------------------------
    |
    | Successful responses are replayed for a repeated Idempotency-Key for this many hours; older keys
    | are pruned by the scheduled `model:prune`. After that a retry still gets 200, because a repeated
    | submit of the consumed revision answers with the current submission.
    |
    */

    'idempotency_ttl_hours' => (int) env('SUBMISSIONS_IDEMPOTENCY_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Image decode limits (decompression bombs)
    |--------------------------------------------------------------------------
    |
    | Images are re-encoded with GD. Their header dimensions are checked first so a small file cannot
    | expand into gigabytes of pixel memory.
    |
    */

    'image_max_pixels' => (int) env('SUBMISSIONS_IMAGE_MAX_PIXELS', 40_000_000),

    'image_max_side' => (int) env('SUBMISSIONS_IMAGE_MAX_SIDE', 12_000),

];
