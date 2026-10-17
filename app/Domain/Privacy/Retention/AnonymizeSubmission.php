<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Retention;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Privacy\Holds\LegalHolds;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Attachment;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Models\SubmissionEvent;
use App\Domain\Submissions\Projection\SubmissionValueProjector;
use Illuminate\Support\Facades\DB;

/**
 * Anonymizes one submission (ADR 0039), idempotently (anonymized_at). Under a row lock: the data keeps only
 * the non-personal values (SubmissionAnonymizer), device, location and every user reference are cleared,
 * event comments and actors are removed, attachments are deleted and the PDF files removed, while the
 * rendition rows (form/design version, input_hash, file_sha256) stay as proof of what was issued; traces in
 * the mail log, sends, tasks and workflow runs are removed (SubmissionTraces). A legal hold placed meanwhile
 * is re-checked under the lock.
 * submission_values are re-projected from the anonymized data; the revision is bumped so clients refetch.
 * One audit entry `submission.anonymized` (with the rendition hashes).
 */
final readonly class AnonymizeSubmission
{
    public function __construct(private SubmissionValueProjector $projector, private AuditLogger $audit) {}

    public function handle(Submission $submission, string $reason, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($submission, $reason, $actor): bool {
            $locked = Submission::query()->with('formVersion')->whereKey($submission->uuid)->whereNull('anonymized_at')->lockForUpdate()->first();
            if (! $locked instanceof Submission || LegalHolds::heldUnderLock($locked)) {
                return false;
            }
            $files = SubmissionFiles::of($locked->uuid);

            $locked->forceFill([
                'data' => SubmissionAnonymizer::data($locked->formVersion->definition, $locked->data),
                'device' => null, 'geo' => null, 'created_by' => null, 'updated_by' => null, 'submitted_by' => null,
                'revision' => $locked->revision + 1, 'anonymized_at' => now()->toDateTimeString(),
            ])->save();
            SubmissionEvent::query()->where('submission_uuid', $locked->uuid)->update(['comment' => null, 'actor_id' => null]);
            Attachment::query()->where('submission_uuid', $locked->uuid)->delete();
            SubmissionTraces::scrub($locked->uuid);
            $this->reproject($locked);

            $properties = [
                'reason' => $reason, 'form_id' => $locked->form_id, 'number' => $locked->number,
                'attachments_deleted' => count($files->attachments), 'renditions' => $files->renditionHashes,
            ];
            $actor instanceof User
                ? $this->audit->record('submission.anonymized', $locked, $properties, $actor)
                : $this->audit->system('submission.anonymized', $locked, $properties);
            $files->deleteLater(renditions: true);

            return true;
        });
    }

    /**
     * The read model follows the anonymized data. A `changes_requested` submission keeps its last submitted
     * values in the read model (its data may hold unsubmitted edits), so only the removed keys are dropped.
     */
    private function reproject(Submission $submission): void
    {
        $values = DB::table('submission_values')->where('submission_uuid', $submission->uuid);
        if ($submission->status === SubmissionStatus::Draft) {
            $values->delete();
        } elseif ($submission->status === SubmissionStatus::ChangesRequested) {
            $values->whereNotIn('key', SubmissionAnonymizer::keptKeys($submission->formVersion->definition) ?: [''])->delete();
        } else {
            $this->projector->project($submission);
        }
    }
}
