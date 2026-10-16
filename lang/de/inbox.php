<?php

declare(strict_types=1);

return [
    'label' => 'Einreichung',
    'plural' => 'Einreichungen',
    'navigation' => 'Einreichungen',
    'column' => [
        'number' => 'Nummer',
        'form' => 'Formular',
        'version' => 'Version',
        'status' => 'Status',
        'submitter' => 'Eingereicht von',
        'created_at' => 'Begonnen',
        'submitted_at' => 'Eingereicht',
        'updated_at' => 'Letzte Änderung',
        'revision' => 'Revision',
    ],
    'filter' => [
        'from' => 'Eingereicht ab',
        'until' => 'Eingereicht bis',
        'from_indicator' => 'Eingereicht ab :date',
        'until_indicator' => 'Eingereicht bis :date',
    ],
    'empty' => 'Noch keine Einreichungen',
    'detail' => [
        'overview' => 'Übersicht',
        'data' => 'Eingereichte Daten',
        'no_data' => 'Diese Einreichung enthält noch keine Daten.',
        'documents' => 'Dokumente',
        'no_documents' => 'Für diese Einreichung wurden noch keine Dokumente erzeugt.',
        'events' => 'Verlauf',
        'no_events' => 'Noch keine Statuswechsel.',
        'system' => 'System',
        'missing_file' => 'Noch nicht hochgeladen',
        'open_file' => ':name öffnen',
        'rows' => ':count Zeilen',
        'row' => 'Zeile',
    ],
    'value' => ['yes' => 'Ja', 'no' => 'Nein'],
];
