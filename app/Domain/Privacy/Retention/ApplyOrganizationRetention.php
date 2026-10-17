<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Retention;

use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Retention for one organization (ADR 0039), as a queued job inside the organization's TenantContext.
 * Idempotent, so retries are safe; when a batch limit was hit it queues itself again.
 */
#[Tries(3)]
#[Timeout(300)]
final class ApplyOrganizationRetention implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $organizationId) {}

    public function handle(TenantContext $tenant, ApplyRetention $retention): void
    {
        $run = $tenant->run($this->organizationId, fn (): RetentionRun => $retention->handle());
        if ($run->more) {
            $tenant->run($this->organizationId, function (): void {
                self::dispatch($this->organizationId);
            });
        }
    }
}
