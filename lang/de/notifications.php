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
    ],
    'mail' => [
        'body' => "Hallo {{recipient.name}},\n\n{{notification.body}}",
        'preferences_hint' => 'Sie erhalten diese E-Mail aufgrund Ihrer Benachrichtigungseinstellungen. Sie können sie in der App unter Einstellungen → Benachrichtigungen ändern.',
    ],
];
