<?php

declare(strict_types=1);

namespace App\Domain\Reports\Scheduling;

use App\Domain\Mail\Mailables\BrandedMail;
use App\Domain\Mail\Templates\MailTemplate;
use Illuminate\Mail\Attachment;

/**
 * Scheduled report email (BUILD_PROMPT §8.4 v1.0): organization-branded through the mail module, the
 * report's numbers for the recipient as a CSV attachment. Sent through QueueMail (logged, organization
 * sender rules); the CSV travels inside the serialized mailable.
 */
final class ScheduledReportMail extends BrandedMail
{
    /** @param  array<string, string>  $tokens */
    public function __construct(int $organizationId, array $tokens, public readonly string $csv, public readonly string $filename, string $locale)
    {
        parent::__construct($organizationId, new MailTemplate('', ''), $tokens, null, $locale);
    }

    public function template(): MailTemplate
    {
        return new MailTemplate((string) __('reports.mail.subject'), (string) __('reports.mail.body'));
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->csv, $this->filename)->withMime('text/csv')];
    }
}
