<?php

declare(strict_types=1);

namespace App\Domain\Mail\Delivery;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Models\DocumentLink;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves a document link: 404 for unknown tokens, 410 Gone once expired (or when the file was
 * removed by retention), otherwise streams the file and records "document.downloaded".
 */
final readonly class DownloadDocumentLink
{
    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    public function handle(string $token): StreamedResponse
    {
        // Platform lookup: the token is the only credential and identifies the tenant.
        $link = DocumentLink::query()->withoutTenant()->where('token_hash', DocumentLink::hashToken($token))->first();

        if (! $link instanceof DocumentLink) {
            throw new NotFoundHttpException;
        }

        return $this->tenant->run($link->organization_id, fn (): StreamedResponse => $this->stream($link));
    }

    private function stream(DocumentLink $link): StreamedResponse
    {
        $disk = Storage::disk($link->disk);

        if ($link->isExpired() || ! $disk->exists($link->path)) {
            throw new GoneHttpException;
        }

        $link->recordDownload();
        $submission = $link->submission_uuid === null ? null : Submission::query()->find($link->submission_uuid);
        $this->audit->record('document.downloaded', $submission ?? $link, [
            'via' => 'link', 'link_id' => $link->id, 'filename' => $link->filename,
            'submission_uuid' => $link->submission_uuid, 'rendition_id' => $link->rendition_id, 'download_count' => $link->download_count,
        ]);

        return $disk->download($link->path, $link->filename, [
            'Content-Type' => $link->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
