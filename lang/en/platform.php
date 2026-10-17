<?php

declare(strict_types=1);

// The /platform operator panel (BUILD_PROMPT §8.12, ADR 0041).
return [
    'brand' => 'Platform operations',

    'organizations' => [
        'label' => 'Organization',
        'plural' => 'Organizations',
        'name' => 'Name',
        'slug' => 'Slug',
        'members' => 'Active members',
        'created' => 'Created',
        'status' => 'Status',
        'statuses' => [
            'active' => 'Active',
            'archived' => 'Archived',
        ],
    ],

    'members' => [
        'action' => 'Members',
        'title' => 'Members of :organization',
        'name' => 'Name',
        'email' => 'Email',
        'status' => 'Membership',
        'operator' => 'Operator',
        'is_operator' => 'Platform operator',
        'joined' => 'Joined',
    ],

    'impersonation' => [
        'action' => 'Impersonate',
        'modal_heading' => 'Sign in as :name',
        'modal_help' => 'You will see the admin panel as this member for at most :minutes minutes. Start and end are recorded in the audit log of the organization, with your name and the reason.',
        'start' => 'Start impersonation',
        'reason' => 'Reason',
        'reason_help' => 'Required, 10 to 500 characters, e.g. the support ticket and what you need to check.',
        'banner_label' => 'Impersonation',
        'banner' => 'You are signed in as :target on behalf of :operator. The impersonation ends at :until (:minutes min left).',
        'exit' => 'Exit impersonation',
        'expired' => 'The impersonation time limit was reached. You are signed in as yourself again.',
        'api_blocked' => 'The API is not available while an operator impersonates a member.',
        'refused' => [
            'not_operator' => 'Only active platform operators can impersonate members.',
            'already_impersonating' => 'End the current impersonation first.',
            'reason_invalid' => 'Enter a reason of 10 to 500 characters.',
            'organization_unavailable' => 'This organization is archived.',
            'target_is_operator' => 'Platform operators cannot be impersonated.',
            'target_not_admin_member' => 'Only active members with access to the admin panel of this organization can be impersonated.',
        ],
    ],

    'health' => [
        'title' => 'Health',
        'checks' => [
            'queue' => 'Queues (Horizon)',
            'gotenberg' => 'PDF rendering (Gotenberg)',
            'mail' => 'Mail transport',
            'scheduler' => 'Scheduler',
            'disk' => 'Disk',
        ],
        'statuses' => [
            'ok' => 'OK',
            'warn' => 'Warning',
            'fail' => 'Failing',
        ],
        'endpoint' => 'Endpoint',
        'unavailable' => 'The check could not run (:error). Details are in the application log.',
        'queue' => [
            'status' => 'Horizon',
            'statuses' => [
                'running' => 'running',
                'paused' => 'paused',
                'inactive' => 'inactive',
            ],
            'pending' => 'Pending jobs',
            'recently_failed' => 'Recently failed jobs',
            'failed_table' => 'Failed jobs (table)',
            'length' => 'Queue :queue',
            'inactive' => 'Horizon is not running: queued jobs (PDFs, mails, workflows) are not processed.',
            'paused' => 'Horizon is paused.',
            'failures' => '{1} :count job failed recently.|[2,*] :count jobs failed recently.',
            'running' => 'Horizon is running.',
        ],
        'gotenberg' => [
            'not_configured' => 'No Gotenberg URL is configured (GOTENBERG_URL).',
            'up' => 'Gotenberg is reachable.',
            'down' => 'Gotenberg answered with HTTP :status.',
        ],
        'mail' => [
            'mailer' => 'Mailer',
            'transport' => 'Transport',
            'not_delivered' => 'Mail is written to the log, not delivered.',
            'not_probed' => 'Configured; this transport is not probed.',
            'reachable' => 'The SMTP server accepts connections.',
            'unreachable' => 'The SMTP server is not reachable.',
        ],
        'scheduler' => [
            'never' => 'No scheduler run recorded yet (since the last cache clear).',
            'last_run' => 'Last run',
            'ago' => 'Last task run :minutes min ago.',
        ],
        'disk' => [
            'unknown' => 'Free disk space could not be determined.',
            'free' => 'Free',
            'total' => 'Total',
            'percent_free' => ':percent % free on the storage volume.',
        ],
    ],
];
