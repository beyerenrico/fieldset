<?php

declare(strict_types=1);

/*
 * Workflow automation (BUILD_PROMPT §8.3, ADR 0032).
 */
return [

    // §6.5 "submitted → completed: no review configured" (ADR 0032). Off in the test environment (like
    // documents.render_on_submit) so unrelated tests keep a submitted submission; workflow tests enable it.
    'complete_without_review' => (bool) env('WORKFLOWS_COMPLETE_WITHOUT_REVIEW', env('APP_ENV') !== 'testing'),

    // Queue of the step jobs (App\Domain\Workflows\Engine\RunWorkflowStep); null = default queue.
    'queue' => env('WORKFLOWS_QUEUE'),

    // Retries of a failing step with exponential backoff: base, base·3, base·9, … seconds.
    'steps' => [
        'tries' => (int) env('WORKFLOWS_STEP_TRIES', 5),
        'backoff_base' => (int) env('WORKFLOWS_STEP_BACKOFF_BASE', 10),
    ],

    // Runs that stay pending/running without progress this long get their current step queued again.
    'sweep_after_minutes' => (int) env('WORKFLOWS_SWEEP_AFTER_MINUTES', 30),

    'webhooks' => [
        'timeout' => (int) env('WORKFLOWS_WEBHOOK_TIMEOUT', 10),
        'connect_timeout' => (int) env('WORKFLOWS_WEBHOOK_CONNECT_TIMEOUT', 5),
        // SSRF guard (§11): targets must resolve to public addresses. Enable only for development.
        'allow_private_hosts' => (bool) env('WORKFLOWS_WEBHOOK_ALLOW_PRIVATE_HOSTS', false),
    ],

    'review' => [
        // Upper bound for a review step's SLA.
        'max_sla_hours' => 24 * 90,
    ],

];
