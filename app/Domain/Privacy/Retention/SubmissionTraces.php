<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Retention;

use Illuminate\Support\Facades\DB;

/**
 * Personal data a submission leaves outside its own row (ADR 0039): the mail log (recipient, subject and the
 * stored mailable), customer send requests, document links, review tasks (comments, assignee) and workflow
 * runs (trigger actor, step logs and outputs with recipient addresses), and in-app notifications about it
 * (their rendered title and body can quote a review comment or a customer name). Call inside the transaction
 * that anonymizes or deletes the submission.
 */
final class SubmissionTraces
{
    public const string MASKED_EMAIL = 'anonymized@anonymized.invalid';

    /** Anonymization: removes the traces, keeps the operational rows where they matter (sends, tasks, runs). */
    public static function scrub(string $uuid): void
    {
        self::delete($uuid);
        DB::table('document_sends')->where('submission_uuid', $uuid)
            ->update(['recipient_email' => self::MASKED_EMAIL, 'recipient_name' => null, 'error' => null]);
        DB::table('document_links')->where('submission_uuid', $uuid)->delete();
        DB::table('tasks')->where('submission_uuid', $uuid)->update(['comment' => null, 'completed_by' => null, 'assignee_user_id' => null]);

        $runs = DB::table('workflow_runs')->where('submission_uuid', $uuid)->pluck('id')->all();
        if ($runs === []) {
            return;
        }
        DB::table('workflow_runs')->whereIn('id', $runs)->update(['context' => DB::raw("context #- '{trigger,actor_id}'"), 'error' => null]);
        DB::table('workflow_step_runs')->whereIn('workflow_run_id', $runs)->update(['log' => '[]', 'error' => null]);
        // Outputs of unfinished steps carry their idempotency state (e.g. queued mails): kept until they finish.
        DB::table('workflow_step_runs')->whereIn('workflow_run_id', $runs)->whereIn('status', ['completed', 'failed'])->update(['output' => '{}']);
    }

    /**
     * Deletion: the mail log rows of the submission (they would outlive it, the foreign key only nulls) and the
     * in-app notifications about it (StoredNotification keeps the uuid in `data`, no foreign key).
     */
    public static function delete(string $uuid): void
    {
        DB::table('mail_messages')->where('submission_uuid', $uuid)->delete();
        DB::table('notifications')->whereRaw("data->>'submission_uuid' = ?", [$uuid])->delete();
    }
}
