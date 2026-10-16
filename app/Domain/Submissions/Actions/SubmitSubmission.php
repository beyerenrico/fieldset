<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Actions;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Events\SubmissionSubmitted;
use App\Domain\Submissions\Models\Attachment;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Projection\SubmissionValueProjector;
use App\Domain\Submissions\Support\AttachmentReferences;
use App\Domain\Submissions\Support\FormAccessResolver;
use App\Domain\Submissions\Support\NumberAllocator;
use App\Domain\Submissions\Support\SubmissionEvaluator;
use App\Http\Api\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Submit (§9.2): server re-validates and recomputes, checks the attachment manifest, assigns the number
 * in the same transaction as the status change (gap-free), projects values and announces the event.
 * Naturally idempotent: repeating the submit of the revision it consumed (a retry after a lost
 * response, with or without the same Idempotency-Key) answers with the current submission.
 */
final readonly class SubmitSubmission
{
    public function __construct(
        private FormAccessResolver $access,
        private SubmissionEvaluator $evaluator,
        private NumberAllocator $numbers,
        private TransitionSubmission $transition,
        private SubmissionValueProjector $projector,
    ) {}

    public function handle(User $user, Organization $organization, Submission $submission, int $revision): Submission
    {
        [$submitted, $fresh] = DB::transaction(function () use ($user, $organization, $submission, $revision): array {
            $locked = Submission::query()->whereKey($submission->uuid)->lockForUpdate()->firstOrFail();
            if (self::alreadySubmitted($user, $locked, $revision)) {
                return [$locked, false];
            }
            $this->guard($user, $locked, $revision);

            $now = Carbon::now();
            $result = $this->evaluator->evaluate($locked->formVersion, $locked->data, $user, $organization, $now);
            if (! $result->isValid()) {
                throw new ApiException('validation_failed', __('api.validation_failed'), 422, $result->errorsToArray());
            }
            $this->guardAttachments($locked, AttachmentReferences::collect($locked->formVersion->definition, $result->data));

            $locked->forceFill([
                'data' => $result->data,
                'revision' => $locked->revision + 1,
                'number' => $locked->number ?? $this->numbers->allocate($locked->formVersion, $now->copy()->setTimezone($organization->timezone)),
                'submitted_by' => $user->id,
                'submitted_at' => $now,
                'updated_by' => $user->id,
            ]);
            $this->transition->handle($locked, SubmissionStatus::Submitted, $user, null, 'submission.submitted');
            $this->projector->project($locked);

            return [$locked, true];
        });

        if ($fresh) {
            DB::afterCommit(fn () => event(new SubmissionSubmitted($submitted->uuid, $submitted->organization_id)));
        }

        return $submitted;
    }

    /**
     * The submit of `$revision` already happened: this user submitted it, the submit consumed exactly
     * that revision (it increments it) and the submission has not come back for editing since.
     */
    private static function alreadySubmitted(User $user, Submission $submission, int $revision): bool
    {
        return $submission->submitted_by === $user->id
            && $submission->created_by === $user->id
            && $submission->status !== SubmissionStatus::Draft
            && ! $submission->status->isEditable()
            && $revision === $submission->revision - 1;
    }

    private function guard(User $user, Submission $submission, int $revision): void
    {
        if ($submission->created_by !== $user->id) {
            throw new ApiException('forbidden', __('api.forbidden'), 403);
        }
        if (! $submission->status->isEditable()) {
            throw new ApiException('not_editable', __('submissions.errors.not_editable'), 409);
        }
        if ($revision !== $submission->revision) {
            throw new ApiException('revision_conflict', __('submissions.errors.revision_conflict'), 409, ['submission' => SubmissionPresenter::present($submission)]);
        }
        if ($submission->status === SubmissionStatus::Draft && $submission->formVersion->isRetired()) {
            throw new ApiException('version_retired', __('submissions.errors.version_retired'), 409);
        }
        if (! $this->access->canFill($user, $submission->form)) {
            throw new ApiException('form_access_revoked', __('submissions.errors.form_access_revoked'), 403);
        }
    }

    /** @param  list<string>  $referenced */
    private function guardAttachments(Submission $submission, array $referenced): void
    {
        $stored = Attachment::query()->where('submission_uuid', $submission->uuid)->pluck('uuid')
            ->map(fn (mixed $u): string => strtolower((string) $u))->all();
        $missing = array_values(array_diff($referenced, $stored));

        if ($missing !== []) {
            throw new ApiException('attachments_missing', __('submissions.errors.attachments_missing'), 422, $missing);
        }
    }
}
