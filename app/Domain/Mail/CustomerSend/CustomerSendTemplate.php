<?php

declare(strict_types=1);

namespace App\Domain\Mail\CustomerSend;

use App\Domain\Mail\Templates\MailTemplate;

/**
 * Default subject and body of the "send to customer" mail in the organization's locale (DE/EN), with
 * the tokens {{form.name}}, {{submission.number}}, {{org.name}} and {{recipient.name}}. The body says
 * "enclosed" only when the PDF is attached; otherwise it points at the download button BrandedMail adds
 * below the body.
 */
final class CustomerSendTemplate
{
    public static function for(string $locale, ?string $recipientName, bool $attached = true): MailTemplate
    {
        $greeting = $recipientName === null || trim($recipientName) === ''
            ? (string) __('mail.customer_send.greeting', [], $locale)
            : (string) __('mail.customer_send.greeting_named', [], $locale);
        $body = $attached ? 'mail.customer_send.body_attached' : 'mail.customer_send.body_link';

        return new MailTemplate(
            (string) __('mail.customer_send.subject', [], $locale),
            $greeting."\n\n".__($body, [], $locale),
        );
    }
}
