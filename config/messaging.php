<?php

declare(strict_types=1);

/*
 * Email delivery (BUILD_PROMPT §8.8). The transport itself is configured instance-wide in
 * config/mail.php; this file holds the per-organization delivery rules.
 */
return [

    // Queue and retry behaviour of App\Domain\Mail\Jobs\DeliverMail.
    'delivery' => [
        'queue' => env('MESSAGING_QUEUE'),
        'tries' => (int) env('MESSAGING_TRIES', 3),
        'backoff' => [30, 120],
    ],

    // Caps on "send to customer" (API and admin inbox); over the cap the API answers 429 too_many_requests.
    'customer_send' => [
        'per_submission_per_day' => (int) env('MESSAGING_CUSTOMER_SENDS_PER_SUBMISSION_PER_DAY', 10),
        'per_organization_per_hour' => (int) env('MESSAGING_CUSTOMER_SENDS_PER_ORGANIZATION_PER_HOUR', 200),
    ],

    // Documents up to this total size are attached; larger ones are sent as expiring links.
    'attachment_max_bytes' => (int) env('MESSAGING_ATTACHMENT_MAX_BYTES', 10 * 1024 * 1024),

    'links' => [
        'ttl_days' => (int) env('MESSAGING_LINK_TTL_DAYS', 14),
        // Requests per minute and IP on the public download route.
        'rate_limit' => (int) env('MESSAGING_LINK_RATE_LIMIT', 30),
        // Expired links answer 410 Gone until they are pruned this many days after expiry.
        'prune_after_days' => (int) env('MESSAGING_LINK_PRUNE_AFTER_DAYS', 90),
    ],

    'smtp' => [
        // Per-organization SMTP servers must resolve to public addresses (SSRF guard, §11).
        // Enable only for development (Mailpit) or trusted self-hosted relays.
        'allow_private_hosts' => (bool) env('MESSAGING_SMTP_ALLOW_PRIVATE_HOSTS', false),
        'timeout' => (int) env('MESSAGING_SMTP_TIMEOUT', 15),
        // Submission ports allowed for organization SMTP servers (any port with allow_private_hosts).
        'allowed_ports' => [25, 465, 587, 2525],
    ],

];
