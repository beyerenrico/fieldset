<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Steps;

use App\Domain\Submissions\Actions\TransitionSubmission;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Actions\WorkflowDefinitionRules;
use App\Domain\Workflows\Engine\StepContext;
use App\Domain\Workflows\Engine\StepFailedException;
use App\Domain\Workflows\Engine\StepResult;
use Illuminate\Support\Facades\DB;

/**
 * `set_status`: moves the submission to `status` (`completed` or `void` only, ADR 0032: never into or out
 * of a review) through TransitionSubmission (state machine, event row, audit). Already at the target:
 * nothing to do. Another target or a transition the machine forbids fails the run.
 */
final readonly class SetStatusStep implements StepHandler
{
    public function __construct(private TransitionSubmission $transition) {}

    public function execute(StepContext $context): StepResult
    {
        $target = SubmissionStatus::tryFrom((string) $context->string('status'));
        if (! $target instanceof SubmissionStatus) {
            throw new StepFailedException(__('workflows.errors.invalid_status'));
        }
        if (! in_array($target, WorkflowDefinitionRules::SET_STATUS_TARGETS, true)) {
            throw new StepFailedException(__('workflows.errors.status_not_allowed', ['status' => $target->label()]));
        }
        $uuid = $context->requireSubmission()->uuid;

        return DB::transaction(function () use ($uuid, $target): StepResult {
            $submission = Submission::query()->whereKey($uuid)->lockForUpdate()->firstOrFail();
            $from = $submission->status;
            if ($from === $target) {
                return StepResult::completed(__('workflows.log.status_unchanged', ['status' => $target->label()]));
            }
            if (! $from->canTransitionTo($target)) {
                throw new StepFailedException(__('workflows.errors.invalid_transition', ['from' => $from->label(), 'to' => $target->label()]));
            }
            $this->transition->handle($submission, $target, null);

            return StepResult::completed(
                __('workflows.log.status_set', ['from' => $from->label(), 'to' => $target->label()]),
                ['from' => $from->value, 'to' => $target->value],
            );
        });
    }
}
