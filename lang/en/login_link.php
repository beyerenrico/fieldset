<?php

declare(strict_types=1);

// One-time sign-in links (BUILD_PROMPT_V2 R27/R28, ADR 0056): landing page states and the hand-off notices.
return [
    'title' => 'Sign-in link',
    'continue' => 'Continue',
    'noscript' => 'This sign-in link needs JavaScript. Turn it on and open the link again.',
    'login' => 'Go to the sign-in page',
    'recovery' => [
        'intro' => 'Ask for a new link on the server that runs this instance:',
        'command' => 'fieldset login-link <email>',
        'hint' => 'Replace <email> with the email address of your account. Each link works once.',
    ],
    'states' => [
        'valid' => [
            'heading' => 'Signing you in …',
            'body' => 'One moment: this one-time link signs you in.',
        ],
        'used' => [
            'heading' => 'This sign-in link has already been used',
            'body' => 'A sign-in link works only once.',
        ],
        'expired' => [
            'heading' => 'This sign-in link has expired',
            'body' => 'The first link after installation is valid for 60 minutes, every other link for 15 minutes.',
        ],
        'rate_limited' => [
            'heading' => 'Too many attempts',
            'body' => 'Wait a minute, then open the link again.',
        ],
        'invalid' => [
            'heading' => 'This sign-in link is not valid',
            'body' => 'The link is incomplete, or a newer link has replaced it.',
        ],
        'sso_required' => [
            'heading' => 'Sign in with single sign-on',
            'body' => 'Your organization requires single sign-on for this email address, so sign-in links cannot be used. Sign in through your identity provider.',
        ],
    ],
    'mfa' => [
        'offer_title' => 'Your password is set',
        'offer_body' => 'Protect your account with two-factor authentication (MFA): set up an authenticator app on this page.',
        'expired' => 'The sign-in link has expired. Ask for a new one with fieldset login-link <email>.',
    ],
    'operator' => [
        'ready_title' => 'Your password is set',
        'ready_body' => 'You are a platform operator without an organization: sign in to the operator panel, which asks you to set up two-factor authentication (MFA) first.',
    ],
];
