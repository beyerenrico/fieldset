<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Exports;

use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * Writes one data export in the background, inside the export's organization (TenantContext). Dispatched
 * after commit on the exports queue; one attempt: a failure marks the export failed and tells the requester.
 */
#[Tries(1)]
final class RunDataExport implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public function __construct(public readonly int $exportId, public readonly int $organizationId)
    {
        $this->timeout = max(10, (int) config('reports.exports.timeout', 85));
        $this->afterCommit();
        $this->onQueue((string) config('reports.exports.queue', 'exports'));
    }

    public function handle(TenantContext $tenant, DataExportRunner $runner): void
    {
        $tenant->run($this->organizationId, fn () => $runner->run($this->exportId));
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantContext::class)->run($this->organizationId, fn () => app(DataExportRunner::class)->fail($this->exportId, $exception));
    }
}
