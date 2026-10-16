<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Triggers;

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Events\SubmissionStatusChanged;
use App\Domain\Submissions\Models\SubmissionEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starts the workflows of one status change (ADR 0032, M4 review C3/S6): queued after commit, so the
 * submit request neither evaluates conditions nor waits for run creation; retried with backoff; idempotent
 * (one run per workflow, submission and event id; StartWorkflows marks the submission event processed).
 * A start that is lost or fails for good is picked up by SweepUnstartedEvents (`workflows:tick`).
 */
final class StartWorkflowsForEvent implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(
        public int $submissionEventId,
        public string $submissionUuid,
        public int $organizationId,
        public string $fromStatus,
        public string $toStatus,
        public ?int $actorId,
    ) {
        $this->tries = max(1, (int) config('workflows.steps.tries', 5));
        $this->afterCommit();
        $this->onQueue(config('workflows.queue'));
    }

    public static function fromEvent(SubmissionStatusChanged $event): self
    {
        return new self($event->submissionEventId, $event->submissionUuid, $event->organizationId, $event->fromStatus, $event->toStatus, $event->actorId);
    }

    public static function fromRow(SubmissionEvent $row): self
    {
        return new self($row->id, $row->submission_uuid, $row->organization_id, (string) $row->from_status, $row->to_status, $row->actor_id);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 90, 270];
    }

    public function handle(TenantContext $tenant, StartWorkflows $start): void
    {
        $event = new SubmissionStatusChanged($this->submissionEventId, $this->submissionUuid, $this->organizationId, $this->fromStatus, $this->toStatus, $this->actorId);
        $tenant->run($this->organizationId, fn (): array => $start->handle(TriggerEvent::statusChanged($event)));
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Workflow start failed; the sweep retries it', [
            'submission_event_id' => $this->submissionEventId, 'organization_id' => $this->organizationId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
