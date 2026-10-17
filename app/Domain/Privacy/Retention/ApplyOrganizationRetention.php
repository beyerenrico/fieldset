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
 * Time-boxed: no new submission is started after TIME_BOX_SECONDS, and the timeout stays below the queue's
 * retry_after (90 s, config/queue.php), so a slow run is never picked up twice. Idempotent, so retries are
 * safe; when the time box or a batch limit was hit it queues itself again.
 */
#[Tries(3)]
#[Timeout(85)]
final class ApplyOrganizationRetention implements ShouldQueue
{
    use Queueable;

    public const int TIME_BOX_SECONDS = 60;

    public function __construct(public readonly int $organizationId) {}

    public function handle(TenantContext $tenant, ApplyRetention $retention): void
    {
        $until = now()->addSeconds(self::TIME_BOX_SECONDS);
        $run = $tenant->run($this->organizationId, fn (): RetentionRun => $retention->handle($until));
        if ($run->more) {
            $tenant->run($this->organizationId, function (): void {
                self::dispatch($this->organizationId);
            });
        }
    }
}
