<?php

declare(strict_types=1);

namespace App\Domain\Mail\Jobs;

use App\Domain\Audit\AuditLogger;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Mailables\BrandedMail;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Mail\Sending\OrganizationMailer;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one logged mail (§8.8). The tenant travels in the job's Context; the mailable is loaded
 * from the mail_messages row so retries resend exactly what was queued. Errors are stored without
 * SMTP credentials; after the last attempt the message is marked failed and can be retried.
 */
final class DeliverMail implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    public function __construct(public readonly int $mailMessageId)
    {
        $this->tries = max(1, (int) config('messaging.delivery.tries'));
        $this->backoff = array_values(array_map(intval(...), (array) config('messaging.delivery.backoff')));
        $this->onQueue(config('messaging.delivery.queue'));
        $this->afterCommit(); // dispatched inside submit/settings transactions (LEARNINGS #28)
    }

    public function handle(OrganizationMailer $mailer, AuditLogger $audit): void
    {
        $message = MailMessage::query()->find($this->mailMessageId);

        if (! $message instanceof MailMessage || $message->status === MailStatus::Sent) {
            return;
        }

        $message->recordAttempt();
        $mailable = $message->mailable();

        try {
            $sent = $mailer->send($mailable);
        } catch (Throwable $e) {
            $message->recordError($mailer->scrub($e->getMessage()));

            throw $e;
        }

        $message->markSent($sent?->getMessageId());

        if ($mailable instanceof BrandedMail && $mailable->documents !== null && $mailable->hasDocuments()) {
            $submission = $message->submission_uuid === null ? null : Submission::query()->find($message->submission_uuid);
            $audit->record('document.sent', $submission ?? $message, [
                'mail_message_id' => $message->id, 'recipient' => $message->recipient_email,
                'submission_uuid' => $message->submission_uuid, ...$mailable->documents->auditSummary(),
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        // By id, without relying on the worker's Context (the failure may predate hydration).
        $message = MailMessage::query()->withoutTenant()->find($this->mailMessageId);

        if ($message instanceof MailMessage && $message->status !== MailStatus::Sent) {
            $message->markFailed($message->error ?? ($exception?->getMessage() ?: 'Delivery failed.'));
        }
    }
}
