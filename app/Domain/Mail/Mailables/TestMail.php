<?php

declare(strict_types=1);

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Templates\MailTemplate;

/** "Send test email" from the mail settings page: proves sender, transport and branding. */
final class TestMail extends BrandedMail
{
    public function __construct(int $organizationId, ?string $locale = null)
    {
        parent::__construct($organizationId, new MailTemplate('', ''), [], null, $locale);
    }

    public function template(): MailTemplate
    {
        return new MailTemplate((string) __('mail.test.subject'), (string) __('mail.test.body'));
    }
}
