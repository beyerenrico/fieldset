<?php

declare(strict_types=1);

namespace App\Support\Refusal;

use RuntimeException;

/**
 * A domain action refused with a stable, client-localizable error code. `status` is the HTTP status the API answers
 * with; the API layer renders it as the §10 envelope (`ApiExceptionRenderer`), other callers show the message.
 */
class DomainRefusal extends RuntimeException
{
    /**
     * @param  array<string, mixed>|list<mixed>|null  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }
}
