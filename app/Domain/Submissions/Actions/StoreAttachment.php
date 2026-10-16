<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Actions;

use App\Domain\Forms\Schema\DefinitionTools;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Models\Attachment;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Support\UploadInspector;
use App\Http\Api\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use stdClass;

/**
 * `POST /submissions/{uuid}/attachments`: idempotent by client attachment uuid (§9.2). The element must
 * be a photo/file/signature of the pinned form version; files land on the private disk.
 *
 * The row is inserted before the file is written (inside one transaction), so a uuid taken in another
 * organization or by a concurrent upload never leaves an orphan file or overwrites the winner's file.
 */
final readonly class StoreAttachment
{
    private const array KIND_FOR_TYPE = ['photo' => 'photo', 'file' => 'file', 'signature' => 'signature'];

    public function __construct(private UploadInspector $inspector) {}

    /** @return array{0: Attachment, 1: bool} attachment and whether it was created */
    public function handle(User $user, Submission $submission, string $attachmentUuid, string $kind, string $elementKey, string $bytes, ?string $originalName): array
    {
        $existing = $this->existing($attachmentUuid, $submission);
        if ($existing !== null) {
            return [$existing, false];
        }

        if ($submission->created_by !== $user->id || ! $submission->status->isEditable()) {
            throw new ApiException('not_editable', __('submissions.errors.not_editable'), 409);
        }

        $element = $this->element($submission, $elementKey);
        if ((self::KIND_FOR_TYPE[$element->type ?? ''] ?? null) !== $kind) {
            throw new ApiException('validation_failed', __('api.validation_failed'), 422, [['pointer' => '/kind', 'code' => 'invalid_kind']]);
        }

        $validation = $element->validation ?? null;
        $maxKb = $validation instanceof stdClass && is_int($validation->maxSizeKb ?? null) ? $validation->maxSizeKb : intdiv(UploadInspector::MAX_BYTES, 1024);
        $accept = $validation instanceof stdClass && is_array($validation->accept ?? null) ? array_values(array_filter($validation->accept, 'is_string')) : [];
        $file = $this->inspector->inspect($bytes, $kind, $maxKb * 1024, $accept);

        try {
            return [$this->persist($user, $submission, $attachmentUuid, $kind, $elementKey, $originalName, $file), true];
        } catch (UniqueConstraintViolationException) {
            // A concurrent upload stored the same uuid first: answer like a repeat.
            return [$this->existing($attachmentUuid, $submission) ?? throw new ApiException('attachment_conflict', __('submissions.errors.attachment_conflict'), 409), false];
        }
    }

    /**
     * Inserts the row, then writes the file in the same transaction (a failed write rolls the row back).
     *
     * @param  array{bytes: string, mime: string, extension: string}  $file
     */
    private function persist(User $user, Submission $submission, string $uuid, string $kind, string $elementKey, ?string $originalName, array $file): Attachment
    {
        $path = sprintf('attachments/%d/%s/%s.%s', $submission->organization_id, $submission->uuid, $uuid, $file['extension']);

        return DB::transaction(function () use ($user, $submission, $uuid, $kind, $elementKey, $originalName, $file, $path): Attachment {
            $attachment = Attachment::query()->create([
                'uuid' => $uuid, 'submission_uuid' => $submission->uuid, 'kind' => $kind, 'element_key' => $elementKey,
                'original_name' => $originalName !== null ? mb_substr($originalName, 0, 200) : null, 'mime' => $file['mime'],
                'size' => strlen($file['bytes']), 'sha256' => hash('sha256', $file['bytes']), 'disk' => 'local', 'path' => $path,
                'created_by' => $user->id,
            ]);
            if (! Storage::disk('local')->put($path, $file['bytes'])) {
                throw new RuntimeException("Could not write attachment file [{$path}].");
            }

            return $attachment;
        });
    }

    /** The stored attachment for a repeated uuid; a uuid of another submission or organization conflicts. */
    private function existing(string $attachmentUuid, Submission $submission): ?Attachment
    {
        $existing = Attachment::query()->whereKey($attachmentUuid)->first();
        if ($existing === null && ! Attachment::query()->withoutTenant()->whereKey($attachmentUuid)->exists()) {
            return null;
        }
        if ($existing === null || $existing->submission_uuid !== $submission->uuid) {
            throw new ApiException('attachment_conflict', __('submissions.errors.attachment_conflict'), 409);
        }

        return $existing;
    }

    private function element(Submission $submission, string $key): stdClass
    {
        foreach (DefinitionTools::elements($submission->formVersion->definition) as $element) {
            if (($element->key ?? null) === $key) {
                return $element;
            }
        }

        throw new ApiException('validation_failed', __('api.validation_failed'), 422, [['pointer' => '/element_key', 'code' => 'unknown_element']]);
    }
}
