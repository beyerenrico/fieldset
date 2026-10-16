<?php

declare(strict_types=1);

namespace App\Domain\Mail\Enums;

/**
 * State of a "send to customer" request (§9.2). `queued` hands over to the mail log, whose
 * MailMessage carries the delivery status (sent, failed, retry).
 */
enum DocumentSendStatus: string
{
    case WaitingForDocument = 'waiting_for_document';
    case Queued = 'queued';
    case Failed = 'failed';

    public function label(): string
    {
        return __('mail.customer_send.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::WaitingForDocument => 'warning',
            self::Queued => 'info',
            self::Failed => 'danger',
        };
    }
}
