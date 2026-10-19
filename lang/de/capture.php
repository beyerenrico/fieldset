<?php

declare(strict_types=1);

// Das Abfang-Banner des Admin-Panels (BUILD_PROMPT_V2 R24, Plan R59, ADR 0080).
return [
    'label' => 'E-Mail-Abfangmodus',
    'full' => [
        'title' => 'E-Mails werden abgefangen, nicht zugestellt.',
        'body' => 'Jede E-Mail dieser Organisation bleibt im Mail-Catcher der Instanz und erreicht niemanden.',
    ],
    'narrow' => [
        'title' => 'Einladungen, Passwort-Zurücksetzungen und Bestätigungen von E-Mail-Adressen werden abgefangen, nicht zugestellt.',
        'body' => 'Sie laufen immer über den Mailer der Instanz, der sie im Mail-Catcher behält; alle anderen E-Mails stellt Ihr eigener SMTP-Server zu.',
    ],
    'ask_owner' => 'Bitten Sie den Betreiber der Instanz, den E-Mail-Versand einzurichten.',
    'operator' => 'E-Mail-Versand auf dem Server einrichten:',
    'mail_settings' => 'Eigenen SMTP-Server in den E-Mail-Einstellungen eintragen',
    'test_mail' => [
        'subject' => 'form-schema-app: Test-E-Mail',
        'body' => 'Diese Test-E-Mail der form-schema-app-Instanz unter :url bestätigt die neuen SMTP-Einstellungen. E-Mails werden jetzt zugestellt, nicht mehr abgefangen.',
        'install_capture' => 'Diese Test-E-Mail der Installation der form-schema-app-Instanz unter :url bestätigt, dass E-Mails im Mail-Catcher ankommen. E-Mails werden abgefangen, nicht zugestellt, bis SMTP mit `fsa config set mail smtp` eingerichtet ist.',
        'install_smtp' => 'Diese Test-E-Mail der Installation der form-schema-app-Instanz unter :url bestätigt, dass der SMTP-Server ihre E-Mails annimmt.',
    ],
];
