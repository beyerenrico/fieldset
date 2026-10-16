<?php

declare(strict_types=1);

namespace App\Domain\Documents\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a permanent render failure was recorded (ADR 0031). Waiting "send to customer" requests
 * listen to it and fail with the reason `document_failed`. Carries ids and the reason code only.
 */
final readonly class RenditionFailed
{
    use Dispatchable;

    public function __construct(public string $submissionUuid, public int $organizationId, public string $reason) {}
}
