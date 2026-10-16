<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Renditions\RenditionLinks;
use App\Domain\Documents\Renditions\RenditionPresenter;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Support\SubmissionVisibility;
use App\Http\Api\ApiException;
use App\Http\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents of a submission (§10): a short-lived signed URL to the latest rendition, and the signed
 * download itself, which re-checks that the user the link was issued to may still see the submission.
 */
final readonly class DocumentController
{
    public function __construct(private SubmissionVisibility $visibility, private AuditLogger $audit) {}

    /** GET /submissions/{uuid}/document */
    public function show(Request $request, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $submission = preg_match('/^[0-9a-f-]{36}$/i', $uuid) === 1 ? Submission::query()->whereKey(strtolower($uuid))->first() : null;
        if ($submission === null || ! $this->visibility->canView($user, $submission)) {
            throw new ApiException('not_found', __('api.not_found'), 404);
        }
        $rendition = Rendition::query()->where('submission_uuid', $submission->uuid)->orderByDesc('id')->first()
            ?? throw new ApiException('document_not_ready', __('documents.errors.not_ready'), 404);
        $expiresAt = RenditionLinks::expiresAt();

        return ApiResponse::ok(['document' => [
            'url' => RenditionLinks::temporaryUrl($rendition, $user, $expiresAt),
            'expires_at' => $expiresAt->toIso8601String(),
            'rendition' => RenditionPresenter::present($rendition),
        ]]);
    }

    /** GET /documents/{rendition}?user=&expires=&signature= (signed, no session needed) */
    public function download(Request $request, string $rendition, TenantContext $tenant): StreamedResponse
    {
        $record = preg_match('/^[0-9a-f-]{36}$/i', $rendition) === 1
            ? Rendition::query()->withoutTenant()->where('uuid', strtolower($rendition))->first()
            : null;
        if ($record === null) {
            throw new ApiException('not_found', __('api.not_found'), 404);
        }

        return $tenant->run($record->organization_id, function () use ($request, $record): StreamedResponse {
            $user = User::query()->find($request->integer('user'));
            $submission = Submission::query()->find($record->submission_uuid);
            if (! $user instanceof User || ! $user->isActive() || $submission === null || ! $this->visibility->canView($user, $submission)) {
                throw new ApiException('not_found', __('api.not_found'), 404);
            }
            if (! Storage::disk($record->disk)->exists($record->path)) {
                report(new RuntimeException("Rendition file missing [{$record->disk}:{$record->path}]."));

                throw new ApiException('not_found', __('api.not_found'), 404);
            }
            $this->audit->record('submission.pdf_downloaded', $submission, ['rendition' => $record->uuid], $user);

            return Storage::disk($record->disk)->download($record->path, RenditionLinks::fileName($record, $submission->number), [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]);
        });
    }
}
