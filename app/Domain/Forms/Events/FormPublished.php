<?php

declare(strict_types=1);

namespace App\Domain\Forms\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a new form version committed (publish or rollback, §7.6). Workflows with the
 * `form.published` trigger listen to it (M4). Carries ids only.
 */
final readonly class FormPublished
{
    use Dispatchable;

    public function __construct(public int $formId, public int $formVersionId, public int $organizationId, public ?int $actorId) {}
}
