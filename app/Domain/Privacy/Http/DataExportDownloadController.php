<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Http;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Privacy\Models\DataExport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download of a finished data export (ADR 0039, §11 "private storage with signed, expiring URLs"): a signed
 * link that expires with the file, plus a session of the requesting user, still a member of the organization
 * and still allowed (DataExportPolicy). Unknown or foreign exports are a 404; an expired or pruned file is a
 * 410 page. Each download is audited (data_export.downloaded).
 */
final readonly class DataExportDownloadController
{
    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    public function __invoke(Request $request, string $organization, string $export): StreamedResponse|Response
    {
        /** @var User $user */
        $user = $request->user();
        $org = Organization::query()->where('slug', $organization)->first();
        abort_if($org === null || ! $user->isActive() || ! $user->isMemberOf($org), 404);

        return $this->tenant->run($org, function () use ($user, $export): StreamedResponse|Response {
            $record = ctype_digit($export) ? DataExport::query()->find((int) $export) : null;
            abort_if(! $record instanceof DataExport || ! $user->can('download', $record), 404);

            $disk = Storage::disk($record->disk);
            if (! $record->isReady() || ! $disk->exists((string) $record->path)) {
                return response()->view('privacy.export-expired', ['days' => DataExport::keepDays()], 410);
            }
            $this->audit->record('data_export.downloaded', $record, ['kind' => $record->kind->value], $user);

            return $disk->download((string) $record->path, (string) $record->file_name, ['Cache-Control' => 'private, no-store']);
        });
    }
}
