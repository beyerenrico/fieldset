<?php

declare(strict_types=1);

return [
    'status' => [
        'draft' => 'Entwurf', 'submitted' => 'Eingereicht', 'in_review' => 'In Prüfung', 'changes_requested' => 'Änderungen angefordert',
        'approved' => 'Freigegeben', 'completed' => 'Abgeschlossen', 'void' => 'Storniert',
    ],
    'errors' => [
        'invalid_transition' => 'Eine Einreichung kann nicht von :from nach :to wechseln.',
        'revision_conflict' => 'Dieser Entwurf wurde an anderer Stelle geändert. Wählen Sie, welche Fassung gelten soll.',
        'not_editable' => 'Diese Einreichung kann nicht mehr bearbeitet werden.',
        'form_access_revoked' => 'Sie haben keinen Zugriff mehr auf dieses Formular. Bitte wenden Sie sich an Ihr Büro.',
        'version_retired' => 'Diese Formularversion wurde zurückgezogen. Bitte starten Sie das Formular in der aktuellen Version neu.',
        'attachments_missing' => 'Einige Fotos oder Dateien wurden noch nicht hochgeladen.',
        'attachment_conflict' => 'Diese Anhang-ID gehört zu einer anderen Einreichung.',
        'file_too_large' => 'Die Datei ist zu groß.',
        'file_type_not_allowed' => 'Dieser Dateityp ist nicht erlaubt.',
        'idempotency_key_required' => 'Bitte einen Idempotency-Key-Header senden.',
        'idempotency_key_reused' => 'Dieser Idempotency-Key wurde bereits für eine andere Anfrage verwendet.',
        'device_unknown' => 'Unbekanntes Gerät.',
        'device_revoked' => 'Dieses Gerät wurde abgemeldet.',
    ],
];
