<?php

declare(strict_types=1);

// The capture-mode banner of the admin panel (BUILD_PROMPT_V2 R24, plan R59, ADR 0080).
return [
    'label' => 'Mail capture',
    'full' => [
        'title' => 'Mail is captured, not delivered.',
        'body' => 'Every mail this organization sends stays in the instance\'s mail catcher and reaches nobody.',
    ],
    'narrow' => [
        'title' => 'Invitations, password resets and email address confirmations are captured, not delivered.',
        'body' => 'They always use the instance mailer, which keeps them in the mail catcher; your own SMTP server delivers all other mail.',
    ],
    'ask_owner' => 'Ask your instance owner to set up mail delivery.',
    'operator' => 'Set up mail delivery on the server:',
    'mail_settings' => 'Use your own SMTP server in Mail settings',
    'test_mail' => [
        'subject' => 'form-schema-app: test mail',
        'body' => 'This test mail from the form-schema-app instance at :url confirms the new SMTP settings. Mail is now delivered, no longer captured.',
        'install_capture' => 'This test mail from the installation of the form-schema-app instance at :url confirms that mail reaches the mail catcher. Mail is captured, not delivered, until SMTP is set up with `fsa config set mail smtp`.',
        'install_smtp' => 'This test mail from the installation of the form-schema-app instance at :url confirms that the SMTP server accepts its mail.',
    ],
];
