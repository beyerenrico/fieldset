<?php

declare(strict_types=1);

namespace App\Domain\Mail\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Mail\Jobs\DeliverMail;
use App\Domain\Mail\Models\MailMessage;
use LogicException;

/** Puts a failed mail back in the queue with its original content (§8.8 AC). */
final class RetryMail
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(MailMessage $message, ?User $actor = null): void
    {
        if (! $message->isRetryable()) {
            throw new LogicException("Mail message {$message->id} is not failed and cannot be retried.");
        }

        $message->requeue();
        $this->audit->record('mail.retried', $message, ['recipient' => $message->recipient_email, 'attempts' => $message->attempts], $actor);

        DeliverMail::dispatch($message->id);
    }
}
