<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Engine;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\ErrorText;
use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Enums\StepStatus;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Models\WorkflowStepRun;
use App\Domain\Workflows\Support\WorkflowNotifications;
use App\Domain\Workflows\Triggers\TriggerEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * State changes of runs and step runs (ADR 0032). Steps run one after another, each as a queued job
 * dispatched after commit; a step may pause the run (`waiting`) until resume() or end(); a failure is
 * recorded on the step and the run, audited and reported to the organization's admins; retry() restarts
 * a failed run at the failed step.
 */
final readonly class WorkflowRunner
{
    public function __construct(
        private AuditLogger $audit,
        private LifecycleRules $lifecycle,
        private WorkflowNotifications $notifications,
    ) {}

    /**
     * Creates the run of an event unless it exists (one per workflow, submission and event id);
     * a new run gets one pending row per step.
     *
     * @param  array<string, mixed>  $context
     */
    public function create(Workflow $workflow, TriggerEvent $event, array $context): WorkflowRun
    {
        $run = WorkflowRun::query()->createOrFirst(
            ['workflow_id' => $workflow->id, 'submission_uuid' => $event->submissionUuid, 'event_id' => $event->eventId],
            ['trigger' => $workflow->trigger_type->value, 'definition_snapshot' => $workflow->snapshot(), 'context' => $context, 'status' => RunStatus::Pending],
        );
        if ($run->wasRecentlyCreated) {
            foreach ($run->steps() as $index => $step) {
                WorkflowStepRun::query()->create(['workflow_run_id' => $run->id, 'index' => $index, 'type' => (string) $step['type']]);
            }
        }

        return $run;
    }

    /** Queues the first step (after commit); a run without steps completes at once. */
    public function start(WorkflowRun $run): void
    {
        if ($run->steps() === []) {
            $this->finish($run, RunStatus::Completed);

            return;
        }
        StepDispatcher::dispatch($run, 0);
    }

    /**
     * Claims step `$index` for execution: only while the run is pending/running at that step and the
     * step has not finished. Returns null for duplicates and stale jobs.
     *
     * @return array{0: WorkflowRun, 1: WorkflowStepRun}|null
     */
    public function claim(int $runId, int $index): ?array
    {
        return DB::transaction(function () use ($runId, $index): ?array {
            $run = WorkflowRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run instanceof WorkflowRun || ! in_array($run->status, [RunStatus::Pending, RunStatus::Running], true) || $run->current_step !== $index) {
                return null;
            }
            $step = $this->stepRow($run, $index);
            if (in_array($step->status, [StepStatus::Completed, StepStatus::Waiting, StepStatus::Failed], true)) {
                return null;
            }
            $step->forceFill(['status' => StepStatus::Running, 'attempts' => $step->attempts + 1, 'started_at' => $step->started_at ?? now()])->save();
            $run->forceFill(['status' => RunStatus::Running, 'started_at' => $run->started_at ?? now()])->save();

            return [$run, $step];
        });
    }

    /** Applies a handler result: continue with the next step, or pause. */
    public function record(WorkflowRun $run, WorkflowStepRun $step, StepResult $result): void
    {
        DB::transaction(function () use ($run, $step, $result): void {
            $locked = WorkflowRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $current = WorkflowStepRun::query()->whereKey($step->id)->first()?->status;
            if ($locked->current_step !== $step->index || $current !== StepStatus::Running) {
                return; // resumed (a review decided before this commit) or failed meanwhile
            }
            $step->mergeOutput($result->output);
            $step->appendLog('info', $result->message);
            if ($result->waiting) {
                $step->forceFill(['status' => StepStatus::Waiting, 'error' => null])->save();
                $locked->forceFill(['status' => RunStatus::Waiting])->save();

                return;
            }
            $step->forceFill(['status' => StepStatus::Completed, 'finished_at' => now(), 'error' => null])->save();
            $this->advance($locked, $step->index);
        });
    }

    /** A retryable attempt failed: log it on the step (the queue retries with backoff). */
    public function recordAttemptError(WorkflowStepRun $step, Throwable $e): void
    {
        $error = ErrorText::scrub($e->getMessage());
        $step->appendLog('warning', __('workflows.log.attempt_failed', ['attempt' => $step->attempts, 'error' => $error]));
        $step->forceFill(['error' => $error])->save();
    }

    /**
     * Resumes a waiting run (an approved review): the waiting step completes with `$output`, the run
     * continues with the next step. Callers hold a transaction.
     *
     * @param  array<string, mixed>  $output
     */
    public function resume(WorkflowRun $run, array $output, string $message): void
    {
        $locked = $this->waitingRun($run);
        $step = $this->stepRow($locked, $locked->current_step);
        $step->mergeOutput($output);
        $step->appendLog('info', $message);
        $step->forceFill(['status' => StepStatus::Completed, 'finished_at' => now()])->save();
        $this->advance($locked, $step->index);
    }

    /**
     * Ends a waiting run without running the remaining steps (a rejected review). Callers hold a transaction.
     *
     * @param  array<string, mixed>  $output
     */
    public function end(WorkflowRun $run, RunStatus $status, array $output, string $message): void
    {
        $locked = $this->waitingRun($run);
        $step = $this->stepRow($locked, $locked->current_step);
        $step->mergeOutput($output);
        $step->appendLog('info', $message);
        $step->forceFill(['status' => StepStatus::Completed, 'finished_at' => now()])->save();
        $locked->forceFill(['status' => $status, 'finished_at' => now()])->save();
    }

    /** Records a permanent failure (after the last retry or a StepFailedException), audits it and notifies admins. */
    public function fail(int $runId, int $index, ?Throwable $e): void
    {
        $error = ErrorText::scrub($e?->getMessage() ?: (string) __('workflows.errors.unknown'));

        DB::transaction(function () use ($runId, $index, $error): void {
            $run = WorkflowRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run instanceof WorkflowRun || $run->status->isFinished() || $run->current_step !== $index) {
                return;
            }
            $step = $this->stepRow($run, $index);
            $step->appendLog('error', $error);
            $step->forceFill(['status' => StepStatus::Failed, 'error' => $error, 'finished_at' => now()])->save();
            $run->forceFill(['status' => RunStatus::Failed, 'error' => $error, 'finished_at' => now()])->save();
            $this->audit->record('workflow.run_failed', $run, [
                'workflow_id' => $run->workflow_id, 'step' => $index, 'type' => $step->type, 'error' => $error, 'submission_uuid' => $run->submission_uuid,
            ]);
            DB::afterCommit(fn () => $this->notifications->workflowFailed($run));
        });
    }

    /** Manual retry (Filament): a failed run continues at its failed step. */
    public function retry(WorkflowRun $run, User $actor): void
    {
        DB::transaction(function () use ($run, $actor): void {
            $locked = WorkflowRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== RunStatus::Failed) {
                throw new InvalidArgumentException(__('workflows.errors.not_failed'));
            }
            $step = $this->stepRow($locked, $locked->current_step);
            $step->appendLog('info', __('workflows.log.retried', ['name' => $actor->name]));
            $step->forceFill(['status' => StepStatus::Pending, 'error' => null, 'finished_at' => null])->save();
            $locked->forceFill(['status' => RunStatus::Running, 'error' => null, 'finished_at' => null])->save();
            $this->audit->record('workflow.run_retried', $locked, ['workflow_id' => $locked->workflow_id, 'step' => $locked->current_step], $actor);
            StepDispatcher::dispatch($locked, $locked->current_step);
        });
    }

    private function advance(WorkflowRun $run, int $index): void
    {
        $next = $index + 1;
        if ($next >= count($run->steps())) {
            $this->finish($run, RunStatus::Completed);

            return;
        }
        $run->forceFill(['current_step' => $next, 'status' => RunStatus::Running])->save();
        StepDispatcher::dispatch($run, $next);
    }

    private function finish(WorkflowRun $run, RunStatus $status): void
    {
        $run->forceFill(['status' => $status, 'finished_at' => now(), 'started_at' => $run->started_at ?? now(), 'error' => null])->save();
        if ($status === RunStatus::Completed) {
            $this->lifecycle->afterRunCompleted($run);
        }
    }

    /** The run paused at its current step — or still recording the pause (a decision raced the step's commit). */
    private function waitingRun(WorkflowRun $run): WorkflowRun
    {
        $locked = WorkflowRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
        $stepStatus = WorkflowStepRun::query()->where('workflow_run_id', $locked->id)->where('index', $locked->current_step)->first()?->status;
        $recording = $locked->status === RunStatus::Running && $stepStatus === StepStatus::Running;
        if ($locked->status !== RunStatus::Waiting && ! $recording) {
            throw new InvalidArgumentException(__('workflows.errors.not_waiting'));
        }

        return $locked;
    }

    private function stepRow(WorkflowRun $run, int $index): WorkflowStepRun
    {
        return WorkflowStepRun::query()->firstOrCreate(
            ['workflow_run_id' => $run->id, 'index' => $index],
            ['type' => $run->step($index)['type'] ?? 'unknown'],
        );
    }
}
