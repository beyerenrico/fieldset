<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Triggers\Listeners;

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Events\SubmissionStatusChanged;
use App\Domain\Workflows\Triggers\StartWorkflows;
use App\Domain\Workflows\Triggers\TriggerEvent;
use Throwable;

/**
 * Submission triggers (submitted, status_changed, approved, rejected). Runs after the transition
 * committed; errors are reported, never thrown (the transition already succeeded).
 */
final readonly class StartWorkflowsOnStatusChange
{
    public function __construct(private TenantContext $tenant, private StartWorkflows $start) {}

    public function handle(SubmissionStatusChanged $event): void
    {
        try {
            $this->tenant->run($event->organizationId, fn (): array => $this->start->handle(TriggerEvent::statusChanged($event)));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
