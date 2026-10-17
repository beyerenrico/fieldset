<?php

declare(strict_types=1);

return [
    'navigation' => 'API tokens',
    'title' => 'API tokens',
    'subheading' => 'Personal tokens let other systems use the REST API on your behalf, in this organization only. A token can never do more than your role allows.',

    'abilities' => [
        'forms_read' => ['label' => 'Read forms', 'description' => 'Fillable forms and their versions'],
        'submissions_read' => ['label' => 'Read submissions', 'description' => 'Submissions, their data and attachments'],
        'submissions_write' => ['label' => 'Write submissions', 'description' => 'Save drafts, upload attachments, submit, send to customers'],
        'documents_read' => ['label' => 'Read documents', 'description' => 'Download links of generated PDFs'],
        'tasks_read' => ['label' => 'Read review tasks', 'description' => 'Open and completed review tasks'],
        'tasks_write' => ['label' => 'Complete review tasks', 'description' => 'Approve or request changes'],
        'notifications_read' => ['label' => 'Read notifications', 'description' => 'Notifications of this organization; marking them as read'],
    ],

    'expiry' => [
        'days' => '{1} :days day|[2,*] :days days',
        'never' => 'Never',
    ],

    'form' => [
        'create' => 'New token',
        'name' => 'Name',
        'name_help' => 'What the token is for, for example "ERP export".',
        'abilities' => 'Permissions',
        'abilities_help' => 'Grant only what the integration needs.',
        'expires_in' => 'Expires after',
        'submit' => 'Create token',
    ],

    'created' => [
        'title' => 'Copy your new token',
        'description' => 'This is the only time the token is shown. Store it in a safe place, for example the secret store of the integration. Send it as "Authorization: Bearer <token>".',
        'token' => 'Token',
        'organization' => 'Organization ID (X-Organization-Id)',
        'done' => 'I have copied the token',
    ],

    'table' => [
        'name' => 'Name',
        'owner' => 'User',
        'abilities' => 'Permissions',
        'last_used' => 'Last used',
        'never_used' => 'Never used',
        'expires' => 'Expires',
        'never_expires' => 'Never',
        'expired' => 'Expired',
        'created' => 'Created',
        'empty' => 'No API tokens yet',
        'empty_description' => 'Create a token to connect another system to the REST API.',
    ],

    'revoke' => [
        'label' => 'Revoke',
        'heading' => 'Revoke token ":name"?',
        'description' => 'Systems using this token lose access immediately. This cannot be undone.',
        'done' => 'Token revoked',
    ],

    'errors' => [
        'insufficient_ability' => 'This API token does not have the permission this request needs.',
        'session_required' => 'This endpoint is only available to the Field App, not to API tokens.',
        'token_unbound' => 'This API token is not bound to an organization. Create a new token in the dashboard.',
        'create_forbidden' => 'You are not allowed to create API tokens in this organization.',
        'revoke_forbidden' => 'You are not allowed to revoke this API token.',
    ],
];
