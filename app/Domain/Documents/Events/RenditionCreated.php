<?php

declare(strict_types=1);

namespace App\Domain\Documents\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a new rendition committed (not when an automatic render reused the latest one).
 * Waiting "send to customer" requests listen to it (M3-T08). Carries ids only.
 */
final readonly class RenditionCreated
{
    use Dispatchable;

    public function __construct(public int $renditionId, public string $submissionUuid, public int $organizationId) {}
}
