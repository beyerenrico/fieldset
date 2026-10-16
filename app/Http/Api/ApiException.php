<?php

declare(strict_types=1);

namespace App\Http\Api;

use RuntimeException;

/** A domain error with a stable, client-localizable error code. */
class ApiException extends RuntimeException
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
