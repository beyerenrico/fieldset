<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photos, files and signature images in the admin submissions inbox (M3-T09, §11). The URL is signed
 * and expires; the request also needs a session of an admin member of the organization in the URL, and
 * the attachment must belong to a submission the user may see (§8.6). Anything else is a 404, so foreign
 * ids are never confirmed.
 */
final class AttachmentDownloadController
{
    public const int LINK_MINUTES = 60;

    public function __construct(private readonly TenantContext $tenant) {}

    public static function signedUrl(Organization $organization, Attachment $attachment): string
    {
        return URL::temporarySignedRoute('admin.attachments.show', now()->addMinutes(self::LINK_MINUTES), [
            'organization' => $organization->slug,
            'attachment' => $attachment->uuid,
        ]);
    }

    public function __invoke(Request $request, string $organization, string $attachment): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $org = Organization::query()->where('slug', $organization)->first();
        abort_if($org === null || ! $user->canAccessTenant($org), 404);

        /** @var Attachment $record */
        $record = $this->tenant->run($org, function () use ($user, $attachment): Attachment {
            $record = preg_match('/^[0-9a-f-]{36}$/i', $attachment) === 1
                ? Attachment::query()->with('submission')->find(strtolower($attachment))
                : null;
            abort_if($record === null || ! $user->can('view', $record), 404);

            return $record;
        });

        // Images render inline (thumbnails); everything else downloads. The CSP sandboxes a file opened directly.
        return Storage::disk($record->disk)->response($record->path, $record->original_name ?? basename($record->path), [
            'Content-Type' => $record->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=0',
        ], str_starts_with($record->mime, 'image/') ? 'inline' : 'attachment');
    }
}
