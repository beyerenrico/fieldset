<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Mail\CustomerSend\CustomerSendGate;
use App\Domain\Submissions\Models\Attachment;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Tasks\ReviewState;

/** The `submission` object of the API contract (docs/api/field-app-v1.md). */
final class SubmissionPresenter
{
    /**
     * `can_send` and `open_task_id` are computed for the viewer, who must be able to see the submission
     * (every caller presents only visible submissions); without a viewer they are false / null.
     *
     * @return array<string, mixed>
     */
    public static function present(Submission $submission, ?User $viewer = null): array
    {
        $submission->loadMissing(['attachments', 'formVersion']);

        return [
            'uuid' => $submission->uuid,
            'form_id' => $submission->form_id,
            'form_version_id' => $submission->form_version_id,
            'version' => $submission->formVersion->version,
            'status' => $submission->status->value,
            'revision' => $submission->revision,
            'number' => $submission->number,
            'data' => $submission->data,
            'attachments' => $submission->attachments->map(fn (Attachment $a): array => self::attachment($a))->values()->all(),
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'created_at' => $submission->created_at->toIso8601String(),
            'updated_at' => $submission->updated_at->toIso8601String(),
            'can_edit' => $submission->status->isEditable(),
            'can_send' => $viewer instanceof User && CustomerSendGate::allows($viewer, $submission),
            'review' => ReviewState::review($submission),
            'open_task_id' => ReviewState::openTaskId($submission, $viewer),
        ];
    }

    /** @return array<string, mixed> */
    public static function attachment(Attachment $attachment): array
    {
        return [
            'uuid' => $attachment->uuid,
            'kind' => $attachment->kind,
            'element_key' => $attachment->element_key,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'sha256' => $attachment->sha256,
            'url' => url("/api/v1/submissions/{$attachment->submission_uuid}/attachments/{$attachment->uuid}"),
        ];
    }
}
