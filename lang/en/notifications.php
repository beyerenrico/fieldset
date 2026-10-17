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
        'sso_misconfigured' => [
            'title' => 'Single sign-on needs attention: :provider',
            'reasons' => [
                'domain_unverified' => 'The domain :detail lost its DNS verification after several failed checks. Single sign-on and its enforcement no longer apply to it until it is verified again.',
                'break_glass_invalid' => 'The break-glass account of “:provider” is no longer an active Owner with multi-factor authentication: :detail. Please designate a valid break-glass Owner.',
                'discovery' => 'The OpenID Connect discovery document of “:provider” cannot be loaded or is incomplete: :detail',
                'jwks' => 'The signing keys (JWKS) of “:provider” cannot be loaded: :detail',
                'metadata' => 'The SAML metadata of “:provider” cannot be loaded or is incomplete: :detail',
                'issuer' => 'The issuer of “:provider” does not match the configuration: :detail',
                'tenant' => 'A sign-in to “:provider” came from another Microsoft Entra tenant: :detail',
                'audience' => 'The tokens of “:provider” were issued for another client ID: :detail',
                'signature' => 'The token signature of “:provider” could not be verified: :detail',
                'saml_signature' => 'The SAML assertion signature of “:provider” could not be verified: :detail',
                'saml_issuer' => 'The SAML assertions of “:provider” come from another entity ID: :detail',
                'invalid_client' => '“:provider” rejected the client ID or the client secret (has the secret expired?): :detail',
                'config' => 'The configuration of “:provider” is incomplete or invalid: :detail',
                'blocked_host' => 'The address of “:provider” points to a blocked host (private or unsafe network): :detail',
                'other' => 'The identity provider “:provider” is misconfigured (:reason): :detail',
            ],
        ],
    ],
    'mail' => [
        'body' => "Hello {{recipient.name}},\n\n{{notification.body}}",
        'preferences_hint' => 'You receive this email because of your notification settings. You can change them in the app under Settings → Notifications.',
    ],
];
