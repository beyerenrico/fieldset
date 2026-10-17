<?php

declare(strict_types=1);

// Einmal-Anmeldelinks (BUILD_PROMPT_V2 R27/R28, ADR 0056): Zustände der Zielseite und Hinweise der Übergabe.
return [
    'title' => 'Anmeldelink',
    'continue' => 'Weiter',
    'noscript' => 'Dieser Anmeldelink braucht JavaScript. Schalten Sie es ein und öffnen Sie den Link erneut.',
    'login' => 'Zur Anmeldeseite',
    'recovery' => [
        'intro' => 'Fordern Sie auf dem Server, auf dem diese Instanz läuft, einen neuen Link an:',
        'command' => 'fsa login-link <email>',
        'hint' => 'Ersetzen Sie <email> durch die E-Mail-Adresse Ihres Kontos. Jeder Link funktioniert nur einmal.',
    ],
    'states' => [
        'valid' => [
            'heading' => 'Sie werden angemeldet …',
            'body' => 'Einen Moment: Dieser Einmal-Link meldet Sie an.',
        ],
        'used' => [
            'heading' => 'Dieser Anmeldelink wurde bereits verwendet',
            'body' => 'Ein Anmeldelink funktioniert nur einmal.',
        ],
        'expired' => [
            'heading' => 'Dieser Anmeldelink ist abgelaufen',
            'body' => 'Der erste Link nach der Installation gilt 60 Minuten, jeder weitere Link 15 Minuten.',
        ],
        'rate_limited' => [
            'heading' => 'Zu viele Versuche',
            'body' => 'Warten Sie eine Minute und öffnen Sie den Link dann erneut.',
        ],
        'invalid' => [
            'heading' => 'Dieser Anmeldelink ist ungültig',
            'body' => 'Der Link ist unvollständig oder wurde durch einen neueren Link ersetzt.',
        ],
        'sso_required' => [
            'heading' => 'Mit Single Sign-on anmelden',
            'body' => 'Ihre Organisation verlangt für diese E-Mail-Adresse Single Sign-on, daher funktionieren Anmeldelinks nicht. Melden Sie sich bei Ihrem Identitätsanbieter an.',
        ],
    ],
    'mfa' => [
        'offer_title' => 'Ihr Passwort ist gesetzt',
        'offer_body' => 'Schützen Sie Ihr Konto mit Zwei-Faktor-Authentifizierung (MFA): Richten Sie auf dieser Seite eine Authenticator-App ein.',
        'expired' => 'Der Anmeldelink ist abgelaufen. Fordern Sie mit fsa login-link <email> einen neuen an.',
    ],
];
