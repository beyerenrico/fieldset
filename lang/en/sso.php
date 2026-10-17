<?php

declare(strict_types=1);

return [
    // Shown for `sso_error=<code>` (Field App query, admin login flash) and the 422 `sso_required` (ADR 0036).
    'errors' => [
        'sso_required' => 'Your organization requires single sign-on for this email address. Continue with SSO.',
        'sso_failed' => 'Single sign-on failed. Please try again.',
        'sso_denied' => 'The sign-in was cancelled at your identity provider.',
        'account_inactive' => 'Your account or membership is deactivated. Contact your administrator.',
        'not_member' => 'Your account is not a member of this organization. Ask an administrator to invite you.',
        'domain_not_verified' => 'Your email domain is not verified for single sign-on of this organization.',
        'email_unverified' => 'Your identity provider did not confirm your email address.',
        'provider_unavailable' => 'Single sign-on is currently unavailable. Please try again later.',
    ],
    'command' => [
        'none' => 'No SSO provider takes logins.',
        'ok' => 'ok',
        'failed' => 'failed: :reason',
    ],
];
