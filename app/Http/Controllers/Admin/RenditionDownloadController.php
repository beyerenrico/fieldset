<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Renditions\RenditionLinks;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Download" of a PDF in the admin inbox (M3 review #18): the page links here instead of embedding a
 * 5-minute signed URL that expires while the page stays open. Needs an admin session of a member of the
 * organization in the URL and RenditionPolicy::view; then redirects to a freshly minted signed download
 * (which re-checks the user and writes the "downloaded" audit entry). The PDF files of an anonymized
 * submission are gone (ADR 0039): 410 with a user-facing message (errors/410), not reported. Anything else is a 404.
 */
final readonly class RenditionDownloadController
{
    public function __construct(private TenantContext $tenant) {}

    public function __invoke(Request $request, string $organization, string $rendition): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $org = Organization::query()->where('slug', $organization)->first();
        abort_if($org === null || ! $user->isActive() || ! $user->canAccessTenant($org), 404);

        $url = $this->tenant->run($org, function () use ($user, $rendition): string {
            $record = preg_match('/^[0-9a-f-]{36}$/i', $rendition) === 1
                ? Rendition::query()->with('submission')->where('uuid', strtolower($rendition))->first()
                : null;
            abort_if($record === null || ! $user->can('view', $record), 404);
            abort_if($record->submission->isAnonymized(), 410, __('documents.errors.anonymized'));

            return RenditionLinks::temporaryUrl($record, $user);
        });

        return redirect()->away($url);
    }
}
