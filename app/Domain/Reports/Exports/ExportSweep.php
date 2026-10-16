<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports;

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\Models\ReportExport;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Safety net for exports (`reports:sweep-exports`): a worker killed mid-export (OOM, deploy, timeout
 * kill) leaves the export `running` and its temp directory behind. Exports running without progress for
 * twice the job timeout, or still pending after an hour (lost dispatch), are failed and their requester
 * notified; temp directories older than a day are removed.
 */
final readonly class ExportSweep
{
    public function __construct(private TenantContext $tenant, private ExportRunner $runner) {}

    /** @return int exports marked failed */
    public function handle(): int
    {
        $timeout = max(10, (int) config('reports.exports.timeout', 85));
        $stale = ReportExport::query()->withoutTenant()
            ->where(fn ($q) => $q->where(fn ($r) => $r->where('status', ExportStatus::Running->value)->where('updated_at', '<', now()->subSeconds(2 * $timeout)))
                ->orWhere(fn ($p) => $p->where('status', ExportStatus::Pending->value)->where('created_at', '<', now()->subHour())))
            ->get(['id', 'organization_id']);

        foreach ($stale as $export) {
            $this->tenant->run($export->organization_id, fn () => $this->runner->fail($export->id, new RuntimeException('The export stopped without finishing.')));
        }
        $this->removeTempDirectories();

        return $stale->count();
    }

    private function removeTempDirectories(): void
    {
        foreach (glob(sys_get_temp_dir().'/'.ExportRunner::TEMP_PREFIX.'*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (filemtime($directory) < now()->subDay()->getTimestamp()) {
                File::deleteDirectory($directory);
            }
        }
    }
}
