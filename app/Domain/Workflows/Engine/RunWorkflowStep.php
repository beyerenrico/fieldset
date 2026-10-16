<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Engine;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Enums\StepType;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Models\WorkflowStepRun;
use App\Domain\Workflows\Steps\StepHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Traits\Localizable;
use Throwable;

/**
 * Executes one step of a run (§8.3 "steps run as queued jobs"). Carries ids only; the tenant is restored
 * from the organization id. A duplicate or stale job is a no-op (WorkflowRunner::claim). Retryable errors
 * retry with exponential backoff (workflows.steps.*); a StepFailedException or the last failed attempt
 * fails the run. The timeout stays below the queue's retry_after (90 s).
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
        return [new WithoutOverlapping("workflow-step:{$this->runId}:{$this->stepIndex}")->releaseAfter(15)->expireAfter(120)];
    }

    public function handle(TenantContext $tenant, WorkflowRunner $runner): void
    {
        $tenant->run($this->organizationId, function () use ($runner): void {
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
