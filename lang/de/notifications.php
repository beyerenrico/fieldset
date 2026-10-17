<?php

declare(strict_types=1);

return [
    'open' => 'Öffnen',
    'events' => [
        'review_assigned' => 'Mir wurde eine Prüfung zugewiesen',
        'changes_requested' => 'Änderungen an meiner Einreichung angefordert',
        'submission_approved' => 'Meine Einreichung wurde freigegeben',
        'workflow_failed' => 'Ein Workflow ist fehlgeschlagen (Administration)',
        'form_available' => 'Ein neues oder geändertes Formular ist verfügbar',
        'workflow_notice' => 'Nachrichten aus Workflows',
        'export_ready' => 'Ein angeforderter Export ist fertig',
        'sso_misconfigured' => 'Single Sign-on ist fehlerhaft konfiguriert (Administration)',
    ],
    'channels' => ['database' => 'In der App', 'mail' => 'E-Mail'],
    'preferences' => [
        'title' => 'Benachrichtigungen',
        'description' => 'Legen Sie für jedes Ereignis fest, wie Sie benachrichtigt werden. Die Einstellungen gelten in allen Ihren Organisationen.',
        'save' => 'Speichern',
        'saved' => 'Benachrichtigungseinstellungen gespeichert',
    ],
    'messages' => [
        'form_available' => ['title' => 'Neues Formular: :form', 'body' => '„:form“ ist jetzt in der App verfügbar.'],
        'form_updated' => ['title' => 'Formular aktualisiert: :form', 'body' => 'Version :version von „:form“ ist in der App verfügbar.'],
        'sso_misconfigured' => [
            'title' => 'Single Sign-on erfordert Ihre Aufmerksamkeit: :provider',
            'reasons' => [
                'domain_unverified' => 'Die Domain :detail hat nach mehreren fehlgeschlagenen Prüfungen ihre DNS-Verifizierung verloren. Single Sign-on und dessen Erzwingung gelten für sie erst wieder nach einer erneuten Verifizierung.',
                'break_glass_invalid' => 'Das Notfallkonto von „:provider“ ist kein aktiver Owner mit Multi-Faktor-Authentifizierung mehr: :detail. Bitte bestimmen Sie einen gültigen Notfall-Owner.',
                'discovery' => 'Das OpenID-Connect-Discovery-Dokument von „:provider“ kann nicht geladen werden oder ist unvollständig: :detail',
                'jwks' => 'Die Signaturschlüssel (JWKS) von „:provider“ können nicht geladen werden: :detail',
                'metadata' => 'Die SAML-Metadaten von „:provider“ können nicht geladen werden oder sind unvollständig: :detail',
                'issuer' => 'Der Issuer von „:provider“ passt nicht zur Konfiguration: :detail',
                'tenant' => 'Eine Anmeldung bei „:provider“ kam aus einem anderen Microsoft-Entra-Mandanten: :detail',
                'audience' => 'Die Tokens von „:provider“ wurden für eine andere Client-ID ausgestellt: :detail',
                'signature' => 'Die Tokensignatur von „:provider“ konnte nicht geprüft werden: :detail',
                'saml_signature' => 'Die Signatur der SAML-Assertion von „:provider“ konnte nicht geprüft werden: :detail',
                'saml_issuer' => 'Die SAML-Assertions von „:provider“ stammen von einer anderen Entity-ID: :detail',
                'invalid_client' => '„:provider“ hat die Client-ID oder das Client-Geheimnis abgelehnt (ist das Geheimnis abgelaufen?): :detail',
                'config' => 'Die Konfiguration von „:provider“ ist unvollständig oder ungültig: :detail',
                'blocked_host' => 'Die Adresse von „:provider“ zeigt auf einen gesperrten Host (privates oder unsicheres Netz): :detail',
                'other' => 'Der Identitätsanbieter „:provider“ ist fehlerhaft konfiguriert (:reason): :detail',
            ],
        ],
    ],
    'mail' => [
        'body' => "Hallo {{recipient.name}},\n\n{{notification.body}}",
        'preferences_hint' => 'Sie erhalten diese E-Mail aufgrund Ihrer Benachrichtigungseinstellungen. Sie können sie in der App unter Einstellungen → Benachrichtigungen ändern.',
    ],
];
