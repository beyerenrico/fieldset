<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Engine;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Instance\Runtime\RuntimeSource;
use App\Domain\Instance\Runtime\RuntimeStateReader;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Enums\StepType;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Models\WorkflowStepRun;
use App\Domain\Workflows\Steps\StepHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Traits\Localizable;
use Throwable;

/**
 * Executes one step of a run (§8.3 "steps run as queued jobs"). Carries ids only; the tenant is restored
 * from the organization id. A duplicate or stale job is a no-op (WorkflowRunner::claim). Retryable errors
 * retry with exponential backoff (workflows.steps.*); a StepFailedException or the last failed attempt
 * fails the run. The timeout stays below the queue's retry_after (90 s). While the instance pauses webhooks after
 * a move, a webhook step is held unclaimed (heldAfterMove()).
 */
#[Timeout(75)]
final class RunWorkflowStep implements ShouldQueue
{
    use Localizable;
    use Queueable;

    public int $tries;

    public function __construct(public int $runId, public int $stepIndex, public int $organizationId)
    {
        $this->tries = max(1, (int) config('workflows.steps.tries', 5));
        $this->onQueue(config('workflows.queue'));
    }

    /**
     * Exponential backoff: base, base·3, base·9, … seconds.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        $base = max(1, (int) config('workflows.steps.backoff_base', 10));

        return array_map(static fn (int $n): int => $base * 3 ** $n, range(0, max(0, $this->tries - 2)));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        // A duplicate of a step that is running right now is dropped, not released: releases count as
        // attempts and could exhaust the tries. The running job (or the stalled-run sweep) finishes the step.
        return [new WithoutOverlapping("workflow-step:{$this->runId}:{$this->stepIndex}")->dontRelease()->expireAfter(120)];
    }

    public function handle(TenantContext $tenant, WorkflowRunner $runner, RuntimeStateReader $runtime): void
    {
        $tenant->run($this->organizationId, function () use ($runner, $runtime): void {
            if ($this->heldAfterMove($runtime)) {
                return;
            }
            $claimed = $runner->claim($this->runId, $this->stepIndex);
            if ($claimed !== null) {
                $organization = Organization::query()->findOrFail($claimed[0]->organization_id);
                // Log lines are written in the organization's language.
                $this->withLocale($organization->locale, fn () => $this->execute($runner, $claimed[0], $claimed[1], $organization));
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantContext::class)->run($this->organizationId, function () use ($exception): void {
            app(WorkflowRunner::class)->fail($this->runId, $this->stepIndex, $exception);
        });
    }

    /**
     * A webhook step waits while the instance pauses webhooks after a move (`fsa install --from-backup`, runtime
     * `webhooks.paused`, ADR 0085): it is not claimed, so no attempt is counted and the run stays as it is; the
     * stalled-run sweep (`workflows:tick`) queues it again until `fsa config set webhooks on` lifts the pause.
     *
     * Fail closed (review S5): only runtime.json itself, or this process's last good value of it, can say webhooks are
     * not paused; an unusable file without one holds them. Without a runtime directory (checkout installs, tests) there
     * is no pause at all.
     */
    private function heldAfterMove(RuntimeStateReader $runtime): bool
    {
        $reading = $runtime->reading();
        $paused = match ($reading->source) {
            RuntimeSource::NotConfigured => false,
            RuntimeSource::File, RuntimeSource::LastGood => $reading->state?->webhooksPaused === true,
            RuntimeSource::Fallback => true,
        };
        if (! $paused) {
            return false;
        }
        $type = WorkflowRun::query()->find($this->runId)?->step($this->stepIndex)['type'] ?? null;
        if ($type !== StepType::Webhook->value) {
            return false;
        }
        Log::info('Webhook step held: webhooks are paused on this instance until the move is confirmed, or runtime.json is unusable.', ['run' => $this->runId, 'step' => $this->stepIndex, 'runtime' => $reading->source->value]);

        return true;
    }

    private function execute(WorkflowRunner $runner, WorkflowRun $run, WorkflowStepRun $step, Organization $organization): void
    {
        try {
            $result = $this->handler($run)->execute($this->context($run, $step, $organization));
        } catch (StepFailedException $e) {
            $runner->fail($this->runId, $this->stepIndex, $e);
            $this->fail($e); // no retries; failed() finds the run already failed

            return;
        } catch (Throwable $e) {
            $runner->recordAttemptError($step, $e);

            throw $e;
        }

        $runner->record($run, $step, $result);
    }

    private function handler(WorkflowRun $run): StepHandler
    {
        $type = StepType::tryFrom($run->step($this->stepIndex)['type'] ?? '')
            ?? throw new StepFailedException(__('workflows.errors.unknown_step'));

        return app($type->handler());
    }

    private function context(WorkflowRun $run, WorkflowStepRun $step, Organization $organization): StepContext
    {
        $data = $run->step($this->stepIndex)['data'] ?? [];
        $submission = $run->submission_uuid === null ? null : Submission::query()->find($run->submission_uuid);

        return new StepContext($run, $step, is_array($data) ? $data : [], $organization, $submission);
    }
}
