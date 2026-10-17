<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Retention;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Privacy\Holds\LegalHolds;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\DB;

/**
 * Deletes one submission for retention (ADR 0039): the row and everything that cascades from it (values,
 * events, attachments, renditions, sends, links, tasks, workflow runs), its mail log rows, and the stored
 * files after commit. A legal hold placed meanwhile is re-checked under the lock.
 * Rendition rows are immutable; their delete needs the session flag the renditions trigger checks
 * (app.allow_immutable_delete), switched off again right after. One audit entry `submission.deleted` keeps
 * the number and the rendition hashes.
 */
final readonly class DeleteSubmissionRecord
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Submission $submission, string $reason, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($submission, $reason, $actor): bool {
            $locked = Submission::query()->whereKey($submission->uuid)->lockForUpdate()->first();
            if (! $locked instanceof Submission || LegalHolds::heldUnderLock($locked)) {
                return false;
            }
            $files = SubmissionFiles::of($locked->uuid);
            SubmissionTraces::deleteMail($locked->uuid);

            // Transaction-local: a failed delete rolls the flag back with the transaction.
            DB::select("SELECT set_config('app.allow_immutable_delete', 'on', true)");
            Submission::query()->whereKey($locked->uuid)->delete();
            DB::select("SELECT set_config('app.allow_immutable_delete', 'off', true)");

            $properties = [
                'reason' => $reason, 'form_id' => $locked->form_id, 'number' => $locked->number, 'status' => $locked->status->value,
                'attachments_deleted' => count($files->attachments), 'renditions' => $files->renditionHashes,
            ];
            $actor instanceof User
                ? $this->audit->record('submission.deleted', $locked, $properties, $actor)
                : $this->audit->system('submission.deleted', $locked, $properties);
            $files->deleteLater(renditions: true);

            return true;
        });
    }
}
