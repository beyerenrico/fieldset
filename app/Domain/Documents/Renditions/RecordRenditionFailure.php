<?php

declare(strict_types=1);

namespace App\Domain\Documents\Renditions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\ErrorText;
use App\Domain\Documents\Events\RenditionFailed;
use App\Domain\Documents\Models\RenditionFailure;
use App\Domain\Documents\Rendering\RenderBudgetExceededException;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records that rendering a submission failed permanently (ADR 0031): one row per submission (repeated
 * failures count up), one `submission.pdf_failed` audit entry per failure, and RenditionFailed after commit
 * so waiting sends fail with a fixed reason. The stored message is scrubbed; users see a fixed text.
 * Runs inside the submission's tenant, under the per-submission rendition lock.
 */
final readonly class RecordRenditionFailure
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Submission $submission, Throwable $error, string $renderReason, ?User $actor = null): RenditionFailure
    {
        $reason = $error instanceof RenderBudgetExceededException ? RenditionFailure::BUDGET_EXCEEDED : RenditionFailure::RENDER_FAILED;
        $message = ErrorText::scrub(($error->getPrevious() ?? $error)->getMessage());

        return DB::transaction(function () use ($submission, $reason, $message, $renderReason, $actor): RenditionFailure {
            DB::select('select pg_advisory_xact_lock(?)', [RenderSubmission::lockId($submission->uuid)]);
            $failure = RenditionFailure::query()->where('submission_uuid', $submission->uuid)->first()
                ?? new RenditionFailure(['submission_uuid' => $submission->uuid, 'attempts' => 0]);
            $failure->fill(['reason' => $reason, 'message' => $message, 'attempts' => $failure->attempts + 1, 'failed_at' => now()])->save();

            $this->audit->record('submission.pdf_failed', $submission, [
                'reason' => $reason, 'render_reason' => $renderReason, 'attempts' => $failure->attempts,
            ], $actor);
            DB::afterCommit(fn () => event(new RenditionFailed($submission->uuid, $submission->organization_id, $reason)));

            return $failure;
        });
    }

    /** A successful render or a new submit makes an earlier failure obsolete. */
    public static function clear(string $submissionUuid): void
    {
        RenditionFailure::query()->where('submission_uuid', $submissionUuid)->delete();
    }
}
