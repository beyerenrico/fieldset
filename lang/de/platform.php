<?php

declare(strict_types=1);

// Das Betreiber-Panel /platform (BUILD_PROMPT §8.12, ADR 0041).
return [
    'brand' => 'Plattformbetrieb',

    'organizations' => [
        'label' => 'Organisation',
        'plural' => 'Organisationen',
        'name' => 'Name',
        'slug' => 'Kürzel',
        'members' => 'Aktive Mitglieder',
        'created' => 'Angelegt',
        'status' => 'Status',
        'statuses' => [
            'active' => 'Aktiv',
            'archived' => 'Archiviert',
        ],
    ],

    'members' => [
        'action' => 'Mitglieder',
        'title' => 'Mitglieder von :organization',
        'name' => 'Name',
        'email' => 'E-Mail',
        'status' => 'Mitgliedschaft',
        'operator' => 'Betreiber',
        'is_operator' => 'Plattformbetreiber',
        'joined' => 'Beigetreten',
    ],

    'impersonation' => [
        'action' => 'Als Mitglied anmelden',
        'modal_heading' => 'Als :name anmelden',
        'modal_help' => 'Sie sehen das Admin-Panel höchstens :minutes Minuten lang als dieses Mitglied. Beginn und Ende werden mit Ihrem Namen und dem Grund im Audit-Log der Organisation protokolliert.',
        'start' => 'Anmeldung als Mitglied starten',
        'reason' => 'Grund',
        'reason_help' => 'Pflichtfeld, 10 bis 500 Zeichen, z. B. das Support-Ticket und was Sie prüfen müssen.',
        'banner_label' => 'Anmeldung als Mitglied',
        'banner' => 'Sie sind als :target angemeldet, im Auftrag von :operator. Die Anmeldung endet um :until (noch :minutes Min.).',
        'exit' => 'Anmeldung als Mitglied beenden',
        'expired' => 'Die Zeitgrenze der Anmeldung als Mitglied ist erreicht. Sie sind wieder mit Ihrem eigenen Konto angemeldet.',
        'api_blocked' => 'Die API ist nicht verfügbar, während ein Betreiber als Mitglied angemeldet ist.',
        'refused' => [
            'not_operator' => 'Nur aktive Plattformbetreiber können sich als Mitglied anmelden.',
            'already_impersonating' => 'Beenden Sie zuerst die laufende Anmeldung als Mitglied.',
            'reason_invalid' => 'Geben Sie einen Grund mit 10 bis 500 Zeichen ein.',
            'organization_unavailable' => 'Diese Organisation ist archiviert.',
            'target_is_operator' => 'Als Plattformbetreiber kann man sich nicht anmelden.',
            'target_not_admin_member' => 'Nur aktive Mitglieder mit Zugang zum Admin-Panel dieser Organisation sind möglich.',
        ],
    ],

    'health' => [
        'title' => 'Zustand',
        'checks' => [
            'queue' => 'Warteschlangen (Horizon)',
            'gotenberg' => 'PDF-Erzeugung (Gotenberg)',
            'mail' => 'E-Mail-Versand',
            'scheduler' => 'Zeitplaner',
            'disk' => 'Speicherplatz',
        ],
        'statuses' => [
            'ok' => 'OK',
            'warn' => 'Warnung',
            'fail' => 'Fehler',
        ],
        'endpoint' => 'Endpunkt',
        'unavailable' => 'Die Prüfung konnte nicht laufen (:error). Details stehen im Anwendungs-Log.',
        'queue' => [
            'status' => 'Horizon',
            'statuses' => [
                'running' => 'läuft',
                'paused' => 'pausiert',
                'inactive' => 'inaktiv',
            ],
            'pending' => 'Wartende Jobs',
            'recently_failed' => 'Kürzlich fehlgeschlagene Jobs',
            'failed_table' => 'Fehlgeschlagene Jobs (Tabelle)',
            'length' => 'Warteschlange :queue',
            'inactive' => 'Horizon läuft nicht: Jobs (PDFs, E-Mails, Workflows) werden nicht verarbeitet.',
            'paused' => 'Horizon ist pausiert.',
            'failures' => '{1} :count Job ist kürzlich fehlgeschlagen.|[2,*] :count Jobs sind kürzlich fehlgeschlagen.',
            'running' => 'Horizon läuft.',
        ],
        'gotenberg' => [
            'not_configured' => 'Keine Gotenberg-URL konfiguriert (GOTENBERG_URL).',
            'up' => 'Gotenberg ist erreichbar.',
            'down' => 'Gotenberg antwortet mit HTTP :status.',
        ],
        'mail' => [
            'mailer' => 'Mailer',
            'transport' => 'Transport',
            'not_delivered' => 'E-Mails werden ins Log geschrieben, nicht zugestellt.',
            'not_probed' => 'Konfiguriert; dieser Transport wird nicht geprüft.',
            'reachable' => 'Der SMTP-Server nimmt Verbindungen an.',
            'unreachable' => 'Der SMTP-Server ist nicht erreichbar.',
        ],
        'scheduler' => [
            'never' => 'Noch kein Lauf des Zeitplaners erfasst (seit dem letzten Leeren des Caches).',
            'last_run' => 'Letzter Lauf',
            'ago' => 'Letzte Aufgabe vor :minutes Min.',
        ],
        'disk' => [
            'unknown' => 'Der freie Speicherplatz ließ sich nicht ermitteln.',
            'free' => 'Frei',
            'total' => 'Gesamt',
            'percent_free' => ':percent % frei auf dem Speicher-Volume.',
        ],
    ],
];
