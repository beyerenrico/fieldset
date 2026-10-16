<?php

declare(strict_types=1);

namespace App\Domain\Reports\Http;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Reports\Models\ReportExport;
use App\Filament\Admin\Pages\FormExplorer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download of a finished export (§8.4, §11 "private storage"): needs an admin session of a member of
 * the organization in the URL and ReportExportPolicy::download (the requester, still holding
 * reports.view). Unknown or foreign exports are a 404; expired or pruned files a 410 Gone with a friendly
 * page that explains the retention and links back to the explorer.
 */
final readonly class ExportDownloadController
{
    public function __construct(private TenantContext $tenant) {}

    public function __invoke(Request $request, string $organization, string $export): StreamedResponse|Response
    {
        /** @var User $user */
        $user = $request->user();
        $org = Organization::query()->where('slug', $organization)->first();
        abort_if($org === null || ! $user->isActive() || ! $user->canAccessTenant($org), 404);

        return $this->tenant->run($org, function () use ($user, $export, $org): StreamedResponse|Response {
            $record = ctype_digit($export) ? ReportExport::query()->find((int) $export) : null;
            abort_if(! $record instanceof ReportExport || ! $user->can('download', $record), 404);

            $disk = Storage::disk($record->file_disk);
            if (! $record->isReady() || ! $disk->exists((string) $record->file_path)) {
                return response()->view('reports.export-expired', [
                    'days' => max(1, (int) config('reports.exports.keep_days', 7)),
                    'backUrl' => FormExplorer::getUrl(['form' => $record->form_id], panel: 'admin', tenant: $org),
                ], 410);
            }

            return $disk->download((string) $record->file_path, (string) $record->file_name);
        });
    }
}
