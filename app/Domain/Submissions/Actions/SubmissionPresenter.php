<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Mail\CustomerSend\CustomerSendGate;
use App\Domain\Submissions\Models\Attachment;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Tasks\ReviewState;
use Illuminate\Database\Eloquent\Collection;

/** The `submission` object of the API contract (docs/api/field-app-v1.md). */
final class SubmissionPresenter
{
    /**
     * `can_edit`, `can_send` and `open_task_id` are computed for the viewer, who must be able to see the
     * submission (every caller presents only visible submissions); without a viewer they are false / null.
     * `can_edit`: the viewer created it and the status is editable (draft, changes_requested).
     *
     * @return array<string, mixed>
     */
    public static function present(Submission $submission, ?User $viewer = null): array
    {
        return self::presentMany(new Collection([$submission]), $viewer)[0];
    }

    /**
     * A page of submissions: attachments, versions, latest decisions and the viewer's open tasks are loaded
     * once for all rows (M8-T05: per row this was three task and group queries, 150 per API page).
     *
     * @param  Collection<int, Submission>  $submissions
     * @return list<array<string, mixed>>
     */
    public static function presentMany(Collection $submissions, ?User $viewer = null): array
    {
        $submissions->loadMissing(['attachments', 'formVersion']);
        $uuids = array_values($submissions->map(fn (Submission $s): string => $s->uuid)->all());
        $reviews = ReviewState::reviewsOf($uuids);
        $openTasks = ReviewState::openTaskIdsOf($uuids, $viewer);

        return array_values($submissions->map(fn (Submission $submission): array => [
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
            'client_submitted_at' => $submission->client_submitted_at?->toIso8601String(),
            'created_at' => $submission->created_at->toIso8601String(),
            'updated_at' => $submission->updated_at->toIso8601String(),
            'can_edit' => $viewer instanceof User && $submission->created_by === $viewer->id && $submission->status->isEditable(),
            'can_send' => $viewer instanceof User && CustomerSendGate::allows($viewer, $submission),
            'review' => $reviews[$submission->uuid] ?? null,
            'open_task_id' => $openTasks[$submission->uuid] ?? null,
        ])->all());
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
