<?php

declare(strict_types=1);

return [
    'open' => 'Open',
    'events' => [
        'review_assigned' => 'A review is assigned to me',
        'changes_requested' => 'Changes are requested on my submission',
        'submission_approved' => 'My submission is approved',
        'workflow_failed' => 'A workflow failed (administrators)',
        'form_available' => 'A new or updated form is available',
        'workflow_notice' => 'Messages from workflows',
        'export_ready' => 'An export I requested is ready',
        'sso_misconfigured' => 'Single sign-on is misconfigured (administrators)',
    ],
    'channels' => ['database' => 'In the app', 'mail' => 'Email'],
    'preferences' => [
        'title' => 'Notifications',
        'description' => 'Choose how you want to be notified about each event. The settings apply in all your organizations.',
        'save' => 'Save',
        'saved' => 'Notification settings saved',
    ],
    'messages' => [
        'form_available' => ['title' => 'New form: :form', 'body' => '“:form” is now available in the app.'],
        'form_updated' => ['title' => 'Form updated: :form', 'body' => 'Version :version of “:form” is available in the app.'],
    ],
    'mail' => [
        'body' => "Hello {{recipient.name}},\n\n{{notification.body}}",
        'preferences_hint' => 'You receive this email because of your notification settings. You can change them in the app under Settings → Notifications.',
    ],
];
