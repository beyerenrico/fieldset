<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Events\SubmissionStatusChanged;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Models\SubmissionEvent;
use App\Domain\Submissions\Projection\SubmissionValueProjector;
use App\Http\Api\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * The only way to change a submission's status (§6.5): validates the transition, writes one
 * submission_events row and one audit entry, keeps `submission_values.status` in sync and announces
 * SubmissionStatusChanged after commit (workflow triggers). Callers wrap it in their transaction.
 */
final readonly class TransitionSubmission
{
    public function __construct(private AuditLogger $audit, private SubmissionValueProjector $projector) {}

    public function handle(Submission $submission, SubmissionStatus $to, ?User $actor, ?string $comment = null, string $event = 'submission.status_changed'): Submission
    {
        $from = $submission->status;
        if (! $from->canTransitionTo($to)) {
            throw new ApiException('invalid_transition', __('submissions.errors.invalid_transition', ['from' => $from->value, 'to' => $to->value]), 409);
        }

        $submission->status = $to;
        $submission->save();
        $this->projector->syncStatus($submission);

        $row = SubmissionEvent::query()->create([
            'submission_uuid' => $submission->uuid,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'actor_id' => $actor?->id,
            'comment' => $comment,
        ]);

        $this->audit->record($event, $submission, ['from' => $from->value, 'to' => $to->value, 'comment' => $comment], $actor);

        $changed = new SubmissionStatusChanged($row->id, $submission->uuid, $submission->organization_id, $from->value, $to->value, $actor?->id);
        DB::afterCommit(static fn () => event($changed));

        return $submission;
    }
}
