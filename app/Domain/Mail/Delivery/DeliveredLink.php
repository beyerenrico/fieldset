<?php

declare(strict_types=1);

namespace App\Domain\Mail\Delivery;

use Carbon\CarbonImmutable;

/** A document sent as an expiring download link instead of an attachment. */
final readonly class DeliveredLink
{
    public function __construct(
        public int $linkId,
        public string $filename,
        public string $url,
        public CarbonImmutable $expiresAt,
    ) {}
}
