<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports;

use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * Writes one export in the background (BUILD_PROMPT §8.4: "completes in the background and notifies the
 * user"). Dispatched after commit; runs as the export's organization. One attempt: a failure marks the
 * export failed and notifies the user instead of producing duplicate files.
 */
#[Tries(1)]
final class RunExport implements ShouldQueue
{
    use Queueable;

    /** reports.exports.timeout (below the queue's retry_after, see config/reports.php). */
    public int $timeout;

    public function __construct(public readonly int $exportId, public readonly int $organizationId)
    {
        $this->timeout = max(10, (int) config('reports.exports.timeout', 85));
        $this->afterCommit();
        $this->onQueue((string) config('reports.exports.queue', 'default'));
    }

    public function handle(TenantContext $tenant, ExportRunner $runner): void
    {
        $tenant->run($this->organizationId, fn () => $runner->run($this->exportId));
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantContext::class)->run($this->organizationId, fn () => app(ExportRunner::class)->fail($this->exportId, $exception));
    }
}
