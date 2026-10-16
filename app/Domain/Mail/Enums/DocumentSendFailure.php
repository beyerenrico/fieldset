<?php

declare(strict_types=1);

namespace App\Domain\Mail\Enums;

/**
 * Why a "send to customer" request failed (M3 review). Users see the fixed, translated text of the
 * reason; the technical (scrubbed) error stays in `document_sends.error` for support.
 */
enum DocumentSendFailure: string
{
    /** Rendering the PDF failed permanently (ADR 0031). */
    case DocumentFailed = 'document_failed';

    /** The submission was voided before the mail went out. */
    case SubmissionVoid = 'submission_void';

    /** At delivery the request no longer passed CustomerSendGate (setting off, requester lost the right). */
    case NotAllowed = 'send_not_allowed';

    /** Queuing the mail failed after the last attempt. */
    case DeliveryFailed = 'delivery_failed';

    public function label(): string
    {
        return __('mail.customer_send.failure.'.$this->value);
    }
}
