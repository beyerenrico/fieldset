<?php

declare(strict_types=1);

return [
    'filters' => [
        'start' => 'Von',
        'end' => 'Bis',
        'hint' => 'Ohne Datum zeigt das Dashboard die letzten :days Tage. Angezeigt: :range.',
    ],

    'kpis' => [
        'heading' => 'Kennzahlen',
        'submissions' => 'Einreichungen',
        'pending_reviews' => 'Offene Prüfungen',
        'pending_reviews_hint' => 'Aktuell offene Prüfaufgaben',
        'stale_drafts' => 'Liegengebliebene Entwürfe',
        'stale_drafts_hint' => 'Seit :days Tagen nicht bearbeitet',
        'documents_sent' => 'Versendete Dokumente',
    ],

    'chart' => [
        'heading' => 'Einreichungen im Zeitverlauf je Formular',
        'other_forms' => 'Weitere Formulare',
        'empty' => 'Keine Einreichungen in diesem Zeitraum.',
    ],

    'review_queue' => [
        'heading' => 'Prüfungen',
        'empty' => 'Nichts zu prüfen.',
        'overdue' => 'Überfällig',
    ],

    'recent_submissions' => [
        'heading' => 'Neueste Einreichungen',
        'empty' => 'Keine Einreichungen in diesem Zeitraum.',
    ],

    'workflow_failures' => [
        'heading' => 'Fehlgeschlagene Workflows',
        'empty' => 'Keine fehlgeschlagenen Workflow-Läufe in diesem Zeitraum.',
    ],

    'draft_forms' => [
        'heading' => 'Formularentwürfe',
        'empty' => 'Keine unveröffentlichten Formulare.',
    ],

    'recently_published' => [
        'heading' => 'Zuletzt veröffentlicht',
        'empty' => 'In diesem Zeitraum wurden keine Versionen veröffentlicht.',
    ],

    'columns' => [
        'number' => 'Nummer',
        'form' => 'Formular',
        'status' => 'Status',
        'submitter' => 'Eingereicht von',
        'submitted_at' => 'Eingereicht',
        'due_at' => 'Fällig',
        'created_at' => 'Angefordert',
        'workflow' => 'Workflow',
        'error' => 'Fehler',
        'failed_at' => 'Fehlgeschlagen am',
        'updated_at' => 'Zuletzt bearbeitet',
        'version' => 'Version',
        'publisher' => 'Veröffentlicht von',
        'published_at' => 'Veröffentlicht',
    ],
];
