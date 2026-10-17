<?php

declare(strict_types=1);

return [
    'navigation' => 'API-Tokens',
    'title' => 'API-Tokens',
    'subheading' => 'Mit persönlichen Tokens nutzen andere Systeme die REST-API in Ihrem Namen, nur in dieser Organisation. Ein Token kann nie mehr, als Ihre Rolle erlaubt.',

    'abilities' => [
        'forms_read' => ['label' => 'Formulare lesen', 'description' => 'Ausfüllbare Formulare und ihre Versionen'],
        'submissions_read' => ['label' => 'Einreichungen lesen', 'description' => 'Einreichungen, ihre Daten und Anhänge'],
        'submissions_write' => ['label' => 'Einreichungen schreiben', 'description' => 'Entwürfe speichern, Anhänge hochladen, einreichen, an Kunden senden'],
        'documents_read' => ['label' => 'Dokumente lesen', 'description' => 'Download-Links erzeugter PDFs'],
        'tasks_read' => ['label' => 'Prüfaufgaben lesen', 'description' => 'Offene und erledigte Prüfaufgaben'],
        'tasks_write' => ['label' => 'Prüfaufgaben erledigen', 'description' => 'Freigeben oder Änderungen anfordern'],
        'notifications_read' => ['label' => 'Benachrichtigungen lesen', 'description' => 'Benachrichtigungen dieser Organisation; als gelesen markieren'],
    ],

    'expiry' => [
        'days' => '{1} :days Tag|[2,*] :days Tage',
        'never' => 'Nie',
    ],

    'form' => [
        'create' => 'Neues Token',
        'name' => 'Name',
        'name_help' => 'Wofür das Token gedacht ist, zum Beispiel „ERP-Export“.',
        'abilities' => 'Berechtigungen',
        'abilities_help' => 'Nur gewähren, was die Integration braucht.',
        'expires_in' => 'Läuft ab nach',
        'submit' => 'Token erstellen',
    ],

    'created' => [
        'title' => 'Neues Token kopieren',
        'description' => 'Das Token wird nur dieses eine Mal angezeigt. Bewahren Sie es sicher auf, zum Beispiel im Geheimnisspeicher der Integration. Senden Sie es als „Authorization: Bearer <Token>“.',
        'token' => 'Token',
        'organization' => 'Organisations-ID (X-Organization-Id)',
        'done' => 'Ich habe das Token kopiert',
    ],

    'table' => [
        'name' => 'Name',
        'owner' => 'Benutzer',
        'abilities' => 'Berechtigungen',
        'last_used' => 'Zuletzt verwendet',
        'never_used' => 'Nie verwendet',
        'expires' => 'Läuft ab',
        'never_expires' => 'Nie',
        'expired' => 'Abgelaufen',
        'created' => 'Erstellt',
        'empty' => 'Noch keine API-Tokens',
        'empty_description' => 'Erstellen Sie ein Token, um ein anderes System mit der REST-API zu verbinden.',
    ],

    'revoke' => [
        'label' => 'Widerrufen',
        'heading' => 'Token „:name“ widerrufen?',
        'description' => 'Systeme mit diesem Token verlieren sofort den Zugriff. Das lässt sich nicht rückgängig machen.',
        'done' => 'Token widerrufen',
    ],

    'errors' => [
        'insufficient_ability' => 'Diesem API-Token fehlt die Berechtigung für diese Anfrage.',
        'session_required' => 'Dieser Endpunkt steht nur der Field App zur Verfügung, nicht API-Tokens.',
        'token_unbound' => 'Dieses API-Token ist an keine Organisation gebunden. Erstellen Sie im Dashboard ein neues Token.',
        'create_forbidden' => 'Sie dürfen in dieser Organisation keine API-Tokens erstellen.',
        'revoke_forbidden' => 'Sie dürfen dieses API-Token nicht widerrufen.',
    ],
];
