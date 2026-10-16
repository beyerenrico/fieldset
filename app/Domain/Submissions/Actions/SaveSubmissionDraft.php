<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Models\SubmissionEvent;
use App\Domain\Submissions\Support\FormAccessResolver;
use App\Domain\Submissions\Support\SubmissionVisibility;
use App\Http\Api\ApiException;
use FormSchema\Json\Json;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * `PUT /submissions/{uuid}`: create (revision 1) or update a draft with optimistic concurrency on
 * `revision` (§9.2). Drafts belong to their creator; data is stored as sent and validated on submit.
 *
 * Retries are safe: a repeat of the last successful save (same user, same data, the revision it
 * consumed — none for a create) answers with the current draft, and a create that loses a race on the
 * same uuid is answered like that sequential repeat.
 */
final readonly class SaveSubmissionDraft
{
    public function __construct(
        private FormAccessResolver $access,
        private SubmissionVisibility $visibility,
        private AuditLogger $audit,
    ) {}

    /** @return array{0: Submission, 1: bool} submission and whether it was created */
    public function handle(User $user, string $uuid, int $formVersionId, stdClass $data, ?int $revision, ?stdClass $device = null, ?stdClass $geo = null): array
    {
        return DB::transaction(function () use ($user, $uuid, $formVersionId, $data, $revision, $device, $geo): array {
            $existing = Submission::query()->whereKey($uuid)->lockForUpdate()->first();
            if ($existing === null) {
                $created = $this->create($user, $uuid, $formVersionId, $data, $device, $geo);
                if ($created instanceof Submission) {
                    return [$created, true];
                }
                // A concurrent request created the uuid after our lookup: continue as its repeat.
                $existing = Submission::query()->whereKey($uuid)->lockForUpdate()->first()
                    ?? throw new ApiException('not_found', __('api.not_found'), 404);
            }

            return [$this->update($user, $existing, $formVersionId, $data, $revision, $device, $geo), false];
        });
    }

    /** @return Submission|null null when the uuid was inserted concurrently */
    private function create(User $user, string $uuid, int $formVersionId, stdClass $data, ?stdClass $device, ?stdClass $geo): ?Submission
    {
        if (Submission::query()->withoutTenant()->whereKey($uuid)->exists()) {
            // The uuid exists in another organization: never reveal or touch it.
            throw new ApiException('not_found', __('api.not_found'), 404);
        }

        $version = FormVersion::query()->with('form')->find($formVersionId);
        if ($version === null) {
            throw new ApiException('not_found', __('api.not_found'), 404);
        }
        $this->guardFillable($user, $version);
        if ($version->isRetired()) {
            throw new ApiException('version_retired', __('submissions.errors.version_retired'), 409);
        }

        try {
            // Savepoint: a unique violation must not abort the surrounding transaction.
            $submission = DB::transaction(fn (): Submission => Submission::query()->create([
                'uuid' => $uuid, 'form_id' => $version->form_id, 'form_version_id' => $version->id, 'data' => $data,
                'revision' => 1, 'created_by' => $user->id, 'updated_by' => $user->id, 'device' => $device, 'geo' => $geo,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        SubmissionEvent::query()->create(['submission_uuid' => $uuid, 'to_status' => 'draft', 'actor_id' => $user->id]);
        $this->audit->record('submission.created', $submission, ['form_id' => $version->form_id, 'version' => $version->version], $user);

        return $submission;
    }

    private function update(User $user, Submission $submission, int $formVersionId, stdClass $data, ?int $revision, ?stdClass $device, ?stdClass $geo): Submission
    {
        $this->guardOwner($user, $submission);
        if ($submission->form_version_id !== $formVersionId) {
            throw new ApiException('version_mismatch', __('submissions.errors.version_mismatch'), 409);
        }
        if ($this->repeatsLastSave($user, $submission, $data, $revision)) {
            return $submission;
        }
        if ($revision !== $submission->revision) {
            throw new ApiException('revision_conflict', __('submissions.errors.revision_conflict'), 409, ['submission' => SubmissionPresenter::present($submission, $user)]);
        }
        if (! $submission->status->isEditable()) {
            throw new ApiException('not_editable', __('submissions.errors.not_editable'), 409);
        }
        $this->guardFillable($user, $submission->formVersion);

        $submission->forceFill([
            'data' => $data, 'revision' => $submission->revision + 1, 'updated_by' => $user->id,
            'device' => $device ?? $submission->device, 'geo' => $geo ?? $submission->geo,
        ])->save();

        return $submission;
    }

    /** The request repeats the save that produced the stored revision (its response was lost). */
    private function repeatsLastSave(User $user, Submission $submission, stdClass $data, ?int $revision): bool
    {
        return $submission->status->isEditable()
            && $submission->updated_by === $user->id
            && ($revision ?? 0) === $submission->revision - 1
            && Json::canonical($data) === Json::canonical($submission->data);
    }

    /** Only the creator edits a draft; others learn nothing about drafts they cannot see. */
    private function guardOwner(User $user, Submission $submission): void
    {
        if ($submission->created_by === $user->id) {
            return;
        }

        throw $this->visibility->canView($user, $submission)
            ? new ApiException('forbidden', __('api.forbidden'), 403)
            : new ApiException('not_found', __('api.not_found'), 404);
    }

    private function guardFillable(User $user, FormVersion $version): void
    {
        if (! $this->access->canFill($user, $version->form)) {
            throw new ApiException('form_access_revoked', __('submissions.errors.form_access_revoked'), 403);
        }
    }
}
