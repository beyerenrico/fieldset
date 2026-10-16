<?php

declare(strict_types=1);

namespace App\Domain\Mail\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Jobs\DeliverMail;
use App\Domain\Mail\Models\MailMessage;
use LogicException;

/**
 * Puts a failed mail back in the queue with its original content (§8.8 AC). The claim is one conditional
 * update (failed → queued): of two concurrent retries (double click, two admins) only one dispatches.
 */
final readonly class RetryMail
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(MailMessage $message, ?User $actor = null): void
    {
        $claimed = $message->transition(MailStatus::Failed, MailStatus::Queued, ['error' => null, 'failed_at' => null, 'queued_at' => now()]);
        if (! $claimed) {
            throw new LogicException("Mail message {$message->id} is not failed and cannot be retried.");
        }

        $this->audit->record('mail.retried', $message, ['recipient' => $message->recipient_email, 'attempts' => $message->attempts], $actor);

        DeliverMail::dispatch($message->id);
    }
}
