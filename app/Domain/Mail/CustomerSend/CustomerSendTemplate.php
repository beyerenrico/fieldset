<?php

declare(strict_types=1);

namespace App\Domain\Mail\CustomerSend;

use App\Domain\Mail\Templates\MailTemplate;

/**
 * Default subject and body of the "send to customer" mail in the organization's locale (DE/EN), with
 * the tokens {{form.name}}, {{submission.number}}, {{org.name}} and {{recipient.name}}. The wording
 * fits both delivery modes: the PDF is attached, or BrandedMail adds a download button below the body.
 */
final class CustomerSendTemplate
{
    public static function for(string $locale, ?string $recipientName): MailTemplate
    {
        $greeting = $recipientName === null || trim($recipientName) === ''
            ? (string) __('mail.customer_send.greeting', [], $locale)
            : (string) __('mail.customer_send.greeting_named', [], $locale);

        return new MailTemplate(
            (string) __('mail.customer_send.subject', [], $locale),
            $greeting."\n\n".(string) __('mail.customer_send.body', [], $locale),
        );
    }
}
