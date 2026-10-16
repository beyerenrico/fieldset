<?php

declare(strict_types=1);

namespace App\Domain\Mail\CustomerSend;

use RuntimeException;

/** A "send to customer" request over one of the caps (CustomerSendLimits); the API answers 429. */
final class CustomerSendLimitExceeded extends RuntimeException
{
    public static function perSubmission(int $limit): self
    {
        return new self((string) __('mail.customer_send.errors.too_many_for_submission', ['max' => $limit]));
    }

    public static function perOrganization(int $limit): self
    {
        return new self((string) __('mail.customer_send.errors.too_many_for_organization', ['max' => $limit]));
    }
}
