<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Triggers\Listeners;

use App\Domain\Forms\Events\FormPublished;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Workflows\Triggers\StartWorkflows;
use App\Domain\Workflows\Triggers\TriggerEvent;
use Throwable;

/** The `form.published` trigger (runs without a submission). Errors are reported, never thrown. */
final readonly class StartWorkflowsOnFormPublished
{
    public function __construct(private TenantContext $tenant, private StartWorkflows $start) {}

    public function handle(FormPublished $event): void
    {
        try {
            $this->tenant->run($event->organizationId, fn (): array => $this->start->handle(TriggerEvent::formPublished($event)));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
