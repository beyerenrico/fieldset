<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Fired after a submit commits; renditions (M3) and workflows (M4) listen to it. */
final readonly class SubmissionSubmitted
{
    use Dispatchable;

    public function __construct(public string $submissionUuid, public int $organizationId) {}
}
