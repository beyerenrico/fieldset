<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a status transition committed (TransitionSubmission). The submission_events row id
 * identifies the transition, so a duplicate delivery of the same event is recognizable (workflows
 * start one run per event id, M4). Carries ids and status values only.
 */
final readonly class SubmissionStatusChanged
{
    use Dispatchable;

    public function __construct(
        public int $submissionEventId,
        public string $submissionUuid,
        public int $organizationId,
        public string $fromStatus,
        public string $toStatus,
        public ?int $actorId,
    ) {}
}
