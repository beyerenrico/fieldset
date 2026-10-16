<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Actions\StoreAttachment;
use App\Domain\Submissions\Actions\SubmissionPresenter;
use App\Domain\Submissions\Models\Attachment;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Support\SubmissionVisibility;
use App\Http\Api\ApiException;
use App\Http\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Idempotent uploads and authorized downloads of submission attachments (§9.2, §11). */
final readonly class AttachmentController
{
    public function __construct(private SubmissionVisibility $visibility) {}

    public function store(Request $request, string $uuid, StoreAttachment $store): JsonResponse
    {
        $submission = $this->submission($request, $uuid); // 404 before validation: never confirm foreign uuids
        $data = $request->validate([
            'file' => ['required', 'file'],
            'attachment_uuid' => ['required', 'uuid'],
            'kind' => ['required', 'in:'.implode(',', Attachment::KINDS)],
            'element_key' => ['required', 'string', 'max:64'],
        ]);
        /** @var UploadedFile $file */
        $file = $data['file'];

        [$attachment, $created] = $store->handle(
            $this->user($request), $submission, strtolower($data['attachment_uuid']), $data['kind'], $data['element_key'],
            (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName(),
        );

        return ApiResponse::ok(['attachment' => SubmissionPresenter::attachment($attachment)], null, $created ? 201 : 200);
    }

    public function show(Request $request, string $uuid, string $attachment): StreamedResponse
    {
        $submission = $this->submission($request, $uuid);
        $record = Attachment::query()->where('submission_uuid', $submission->uuid)->whereKey(strtolower($attachment))->first()
            ?? throw new ApiException('not_found', __('api.not_found'), 404);

        // Images may render inline; everything else downloads. The CSP sandboxes a file opened directly.
        return Storage::disk($record->disk)->response($record->path, $record->original_name ?? basename($record->path), [
            'Content-Type' => $record->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=0',
        ], str_starts_with($record->mime, 'image/') ? 'inline' : 'attachment');
    }

    private function submission(Request $request, string $uuid): Submission
    {
        $submission = preg_match('/^[0-9a-f-]{36}$/i', $uuid) === 1 ? Submission::query()->whereKey(strtolower($uuid))->first() : null;
        if ($submission === null || ! $this->visibility->canView($this->user($request), $submission)) {
            throw new ApiException('not_found', __('api.not_found'), 404);
        }

        return $submission;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
