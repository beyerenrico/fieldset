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

    // Status changes whose workflows were not started after this many minutes (lost or failed start job)
    // are started again by workflows:tick; only the last start_sweep_days days are scanned.
    'start_sweep_after_minutes' => (int) env('WORKFLOWS_START_SWEEP_AFTER_MINUTES', 10),
    'start_sweep_days' => (int) env('WORKFLOWS_START_SWEEP_DAYS', 7),

    // Caps on workflow emails (send_email may read recipients from submitted values): per submission in
    // 24 hours and per organization in one hour. Over a cap the step fails and admins are notified.
    'email' => [
        'per_submission_per_day' => (int) env('WORKFLOWS_EMAILS_PER_SUBMISSION_PER_DAY', 25),
        'per_organization_per_hour' => (int) env('WORKFLOWS_EMAILS_PER_ORGANIZATION_PER_HOUR', 500),
    ],

    'webhooks' => [
        'timeout' => (int) env('WORKFLOWS_WEBHOOK_TIMEOUT', 10),
        'connect_timeout' => (int) env('WORKFLOWS_WEBHOOK_CONNECT_TIMEOUT', 5),
        // SSRF guard (§11): targets must resolve to public addresses and use a web port. Enable only for
        // development (lifts both checks).
        'allow_private_hosts' => (bool) env('WORKFLOWS_WEBHOOK_ALLOW_PRIVATE_HOSTS', false),
        'allowed_ports' => [80, 443, 8080, 8443],
    ],

    'review' => [
        // Upper bound for a review step's SLA.
        'max_sla_hours' => 24 * 90,
    ],

    // Limits of a workflow definition (SaveWorkflow): steps, JSON size of the steps, entries per recipient
    // list (fixed addresses, groups, users, roles) and address fields per send_email step.
    'limits' => [
        'steps' => (int) env('WORKFLOWS_MAX_STEPS', 25),
        'definition_kb' => (int) env('WORKFLOWS_MAX_DEFINITION_KB', 64),
        'recipients' => (int) env('WORKFLOWS_MAX_RECIPIENTS', 50),
        'fields' => (int) env('WORKFLOWS_MAX_ADDRESS_FIELDS', 10),
    ],

];
