<?php

declare(strict_types=1);

namespace App\Domain\Mail\Actions;

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Identity\Tenancy\TenantNotSetException;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Jobs\DeliverMail;
use App\Domain\Mail\Mailables\BrandedMail;
use App\Domain\Mail\Models\MailMessage;
use InvalidArgumentException;

/**
 * The only way to send organization mail (§8.8): logs the message as queued and dispatches
 * DeliverMail after the surrounding transaction commits.
 */
final readonly class QueueMail
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(BrandedMail $mail, string $email, ?string $name = null, ?string $submissionUuid = null): MailMessage
    {
        $organization = $this->tenant->organization() ?? throw TenantNotSetException::make();

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid recipient email address.');
        }
        if ($mail->organizationId !== $organization->id) {
            throw TenantNotSetException::crossTenantWrite($mail::class);
        }

        if (blank($mail->locale)) {
            $mail->locale($organization->locale);
        }
        $mail->to($email, $name);

        $message = MailMessage::query()->create([
            'recipient_email' => $email,
            'recipient_name' => $name,
            'subject' => $mail->localizedSubject(),
            'status' => MailStatus::Queued,
            'submission_uuid' => $submissionUuid,
            'mailable_class' => $mail::class,
            'payload' => serialize($mail),
            'queued_at' => now(),
        ]);

        DeliverMail::dispatch($message->id);

        return $message;
    }
}
