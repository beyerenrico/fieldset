<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Retention;

use App\Domain\Privacy\Enums\RetentionAction;
use App\Domain\Privacy\Holds\LegalHolds;
use App\Domain\Privacy\Models\RetentionPolicy;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\DB;

/**
 * Retention of one submission (ADR 0039), decided under its row lock, because the batch read can be stale: a
 * draft submitted or a submission resubmitted meanwhile, a policy raised, set to "keep" or switched to delete
 * while the run was going. Locks the submission row, then takes the share lock on the form row
 * (LegalHolds::heldUnderLock) that SaveRetentionPolicy and PlaceLegalHold take for update, and only then
 * re-reads the holds, the form's current policy and the submission's status and due-ness.
 */
final readonly class RetainSubmission
{
    public function __construct(private AnonymizeSubmission $anonymize, private DeleteSubmissionRecord $delete) {}

    /** What was done: deleted, anonymized, or null when the submission is held, gone or no longer due. */
    public function handle(string $uuid): ?RetentionAction
    {
        return DB::transaction(function () use ($uuid): ?RetentionAction {
            $locked = Submission::query()->whereKey($uuid)->lockForUpdate()->first();
            if (! $locked instanceof Submission || LegalHolds::heldUnderLock($locked)) {
                return null;
            }
            $action = self::decide($locked, RetentionPolicy::query()->where('form_id', $locked->form_id)->first());

            return match ($action) {
                RetentionAction::Delete => $this->delete->handle($locked, ApplyRetention::REASON) ? $action : null,
                RetentionAction::Anonymize => $this->anonymize->handle($locked, ApplyRetention::REASON) ? $action : null,
                null => null,
            };
        });
    }

    /**
     * What the policy asks for now: nothing before the submit time (drafts: creation time) is older than the
     * period; then due drafts are deleted (never submitted) and the others deleted or anonymized as it says.
     */
    public static function decide(Submission $submission, ?RetentionPolicy $policy): ?RetentionAction
    {
        $since = $submission->submitted_at ?? $submission->created_at;
        if (! $policy instanceof RetentionPolicy || $policy->months === null || $since === null
            || ! $since->lessThan(ApplyRetention::cutoff($policy->months))) {
            return null;
        }
        if ($submission->status === SubmissionStatus::Draft || $policy->action === RetentionAction::Delete) {
            return RetentionAction::Delete;
        }

        return $submission->anonymized_at === null ? RetentionAction::Anonymize : null;
    }
}
