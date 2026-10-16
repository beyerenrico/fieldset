<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Triggers;

use App\Domain\Forms\Models\Form;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Engine\LifecycleRules;
use App\Domain\Workflows\Engine\WorkflowRunner;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use Illuminate\Support\Facades\DB;

/**
 * Matches an event against the organization's active workflows (trigger, forms, JsonLogic conditions)
 * and starts one run per match — a repeated event finds the existing runs (unique per workflow,
 * submission and event id), so duplicates never run steps twice. After a submit without a workflow that
 * manages the status, the submission moves to `completed` (ADR 0032). Runs inside the tenant.
 */
final readonly class StartWorkflows
{
    public function __construct(
        private ConditionEvaluator $conditions,
        private WorkflowRunner $runner,
        private LifecycleRules $lifecycle,
    ) {}

    /** @return list<WorkflowRun> the runs of this event, new or existing */
    public function handle(TriggerEvent $event): array
    {
        $submission = $event->submissionUuid === null ? null : Submission::query()->find($event->submissionUuid);
        if ($event->submissionUuid !== null && ! $submission instanceof Submission) {
            return [];
        }
        $event = $event->withForm($submission instanceof Submission ? $submission->form_id : $event->formId);
        $form = $event->formId === null ? null : Form::query()->find($event->formId);
        $context = RunContext::build($event, $submission, $form);
        $workflows = Workflow::query()->where('active', true)->whereIn('trigger_type', $event->candidateTypes())->orderBy('id')->get()
            ->filter(fn (Workflow $workflow): bool => $event->matches($workflow) && $this->conditions->passes($workflow->conditions, $context));

        return DB::transaction(function () use ($event, $submission, $workflows): array {
            $runs = [];
            foreach ($workflows as $workflow) {
                $run = $this->runner->create($workflow, $event, ['trigger' => $event->toArray()]);
                if ($run->wasRecentlyCreated) {
                    $this->runner->start($run);
                }
                $runs[] = $run;
            }
            if ($submission instanceof Submission && $event->toStatus === SubmissionStatus::Submitted) {
                $this->lifecycle->afterSubmitted($submission->uuid, $event->eventId);
            }

            return $runs;
        });
    }
}
