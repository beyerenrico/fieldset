<?php

declare(strict_types=1);

namespace App\Domain\Mail\Jobs;

use App\Domain\Audit\AuditLogger;
use App\Domain\Mail\Delivery\DeliveryPlan;
use App\Domain\Mail\Delivery\DocumentDelivery;
use App\Domain\Mail\Enums\DocumentSendFailure;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Mailables\BrandedMail;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Mail\Sending\OrganizationMailer;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Sends one logged mail (§8.8). The tenant travels in the job's Context; the mailable is loaded from the
 * mail_messages row so retries resend exactly what was queued. Exactly-once as far as the log can tell:
 * each attempt claims the row (queued → sending) atomically; a transport error puts it back to queued for
 * the next attempt; an attempt that finds it still `sending` (a worker died mid-send) marks it failed for
 * a manual retry instead of sending again. Every attempt uses the Message-ID `<mail-{id}@{app host}>` and
 * fresh document links. "Sent" and the `document.sent` audit entry commit together. The timeout stays
 * below the queue's retry_after (90 s), so a slow attempt is never picked up twice.
 */
#[Timeout(60)]
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
        if (! $message instanceof MailMessage || $this->refusedForVoidedSubmission($message)) {
            return;
        }
        if (! $message->claimForSending()) {
            $this->failIfInterrupted($message);

            return;
        }

        $mailable = $this->prepare($message);
        try {
            $sent = $mailer->send($mailable);
        } catch (Throwable $e) {
            $message->releaseAfterError($mailer->scrub($e->getMessage()));

            throw $e;
        }

        DB::transaction(function () use ($message, $mailable, $sent, $audit): void {
            $message->markSent($sent?->getMessageId() ?? $message->messageIdHeader());
            if ($mailable instanceof BrandedMail && $mailable->documents instanceof DeliveryPlan && $mailable->hasDocuments()) {
                $submission = $message->submission_uuid === null ? null : Submission::query()->find($message->submission_uuid);
                $audit->record('document.sent', $submission ?? $message, [
                    'mail_message_id' => $message->id, 'recipient' => $message->recipient_email,
                    'submission_uuid' => $message->submission_uuid, ...$mailable->documents->auditSummary(),
                ]);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        // By id, without relying on the worker's Context (the failure may predate hydration).
        $message = MailMessage::query()->withoutTenant()->find($this->mailMessageId);

        if ($message instanceof MailMessage && $message->status !== MailStatus::Sent) {
            $message->markFailed($message->error ?? ($exception?->getMessage() ?: 'Delivery failed.'));
        }
    }

    /** The stored mailable with this log entry's Message-ID and document links valid from now on. */
    private function prepare(MailMessage $message): Mailable
    {
        $mailable = $message->mailable();
        if ($mailable instanceof BrandedMail) {
            $mailable->refreshDocumentLinks(app(DocumentDelivery::class));
        }
        $id = $message->messageIdHeader();

        return $mailable->withSymfonyMessage(static function (Email $email) use ($id): void {
            $email->getHeaders()->remove('Message-ID');
            $email->getHeaders()->addIdHeader('Message-ID', $id);
        });
    }

    /** A previous attempt claimed the message and never finished: it may have been sent, so never resend. */
    private function failIfInterrupted(MailMessage $message): void
    {
        $message->transition(MailStatus::Sending, MailStatus::Failed, [
            'error' => 'Delivery was interrupted; the mail may or may not have been sent. Check before retrying.',
            'failed_at' => now(),
        ]);
    }

    /** Customer mail of a submission voided after the request never goes out (M3 review #5). */
    private function refusedForVoidedSubmission(MailMessage $message): bool
    {
        $send = DocumentSend::query()->where('mail_message_id', $message->id)->first();
        if (! $send instanceof DocumentSend || $send->submission->status !== SubmissionStatus::Void) {
            return false;
        }
        if ($message->transition(MailStatus::Queued, MailStatus::Failed, ['error' => 'Not sent: the submission was voided.', 'failed_at' => now()])) {
            $send->markFailed(DocumentSendFailure::SubmissionVoid);
        }

        return true;
    }
}
