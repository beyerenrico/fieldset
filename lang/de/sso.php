<?php

declare(strict_types=1);

return [
    // Für `sso_error=<code>` (Field-App-Query, Flash im Admin-Login) und die 422 `sso_required` (ADR 0036).
    'errors' => [
        'sso_required' => 'Ihre Organisation verlangt für diese E-Mail-Adresse Single Sign-on. Weiter mit SSO.',
        'sso_failed' => 'Die Anmeldung per Single Sign-on ist fehlgeschlagen. Bitte versuchen Sie es erneut.',
        'sso_denied' => 'Die Anmeldung wurde bei Ihrem Identitätsanbieter abgebrochen.',
        'account_inactive' => 'Ihr Konto oder Ihre Mitgliedschaft ist deaktiviert. Wenden Sie sich an Ihre Administration.',
        'not_member' => 'Ihr Konto ist kein Mitglied dieser Organisation. Bitten Sie die Administration um eine Einladung.',
        'domain_not_verified' => 'Ihre E-Mail-Domain ist für das Single Sign-on dieser Organisation nicht verifiziert.',
        'email_unverified' => 'Ihr Identitätsanbieter hat Ihre E-Mail-Adresse nicht bestätigt.',
        'provider_unavailable' => 'Single Sign-on ist derzeit nicht verfügbar. Bitte versuchen Sie es später erneut.',
    ],
    'command' => [
        'none' => 'Kein SSO-Anbieter nimmt Anmeldungen an.',
        'ok' => 'ok',
        'failed' => 'fehlgeschlagen: :reason',
    ],
];
