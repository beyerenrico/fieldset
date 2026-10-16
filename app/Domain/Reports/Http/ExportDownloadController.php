<?php

declare(strict_types=1);

namespace App\Domain\Reports\Http;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Reports\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download of a finished export (§8.4, §11 "private storage"): needs an admin session of a member of
 * the organization in the URL and ReportExportPolicy::download (the requester, still holding
 * reports.view). Unknown or foreign exports are a 404; expired or pruned files a 410 Gone.
 */
final readonly class ExportDownloadController
{
    public function __construct(private TenantContext $tenant) {}

    public function __invoke(Request $request, string $organization, string $export): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $org = Organization::query()->where('slug', $organization)->first();
        abort_if($org === null || ! $user->isActive() || ! $user->canAccessTenant($org), 404);

        return $this->tenant->run($org, function () use ($user, $export): StreamedResponse {
            $record = ctype_digit($export) ? ReportExport::query()->find((int) $export) : null;
            abort_if(! $record instanceof ReportExport || ! $user->can('download', $record), 404);

            $disk = Storage::disk($record->file_disk);
            abort_if(! $record->isReady() || ! $disk->exists((string) $record->file_path), 410);

            return $disk->download((string) $record->file_path, (string) $record->file_name);
        });
    }
}
