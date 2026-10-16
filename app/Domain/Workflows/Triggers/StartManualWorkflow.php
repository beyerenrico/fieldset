<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Triggers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Support\SubmissionVisibility;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Collection;

/**
 * The `manual` trigger: "Run workflow" on a submission in the inbox. Allowed for users holding
 * `workflows.manage` or `submissions.review` who can see the submission. Each click is a new event.
 */
final readonly class StartManualWorkflow
{
    public function __construct(private StartWorkflows $start, private SubmissionVisibility $visibility, private AuditLogger $audit) {}

    public function allows(User $user, Submission $submission): bool
    {
        return ($user->hasPermissionIn($submission->organization_id, Permission::WorkflowsManage)
            || $user->hasPermissionIn($submission->organization_id, Permission::SubmissionsReview))
            && $this->visibility->canView($user, $submission);
    }

    /** @return Collection<int, Workflow> active manual workflows that apply to the submission's form */
    public function available(Submission $submission): Collection
    {
        return Workflow::query()->where('active', true)->where('trigger_type', TriggerType::Manual->value)->orderBy('name')->get()
            ->filter(fn (Workflow $w): bool => $w->formIds() === [] || in_array($submission->form_id, $w->formIds(), true))->values();
    }

    /** The run, or null when the workflow's conditions do not match the submission. */
    public function handle(Workflow $workflow, Submission $submission, User $actor): ?WorkflowRun
    {
        $runs = $this->start->handle(TriggerEvent::manual($workflow, $submission->uuid, $actor->id));
        $run = $runs[0] ?? null;
        $this->audit->record('workflow.manual_run', $submission, ['workflow_id' => $workflow->id, 'run_id' => $run?->id], $actor);

        return $run;
    }
}
