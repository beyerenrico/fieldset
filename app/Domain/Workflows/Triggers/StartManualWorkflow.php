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
 * `workflows.manage` or `submissions.review` who can see the submission. Each click is a new event, except
 * a repeated click by the same user within DEDUPE_SECONDS (double click), which finds the first run.
 */
final readonly class StartManualWorkflow
{
    /** Clicks of one user on one workflow and submission within this many seconds start one run. */
    public const int DEDUPE_SECONDS = 10;

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

    /**
     * The run, or null when the workflow's conditions do not match the submission. A repeated click by the
     * same user within DEDUPE_SECONDS returns the run of the first click (one event id per short window).
     */
    public function handle(Workflow $workflow, Submission $submission, User $actor): ?WorkflowRun
    {
        $runs = $this->start->handle(TriggerEvent::manual($workflow, $submission->uuid, $actor->id, $this->eventId($workflow, $submission, $actor)));
        $run = $runs[0] ?? null;
        if (! $run instanceof WorkflowRun || $run->wasRecentlyCreated) {   // a double click is not audited twice
            $this->audit->record('workflow.manual_run', $submission, ['workflow_id' => $workflow->id, 'run_id' => $run?->id], $actor);
        }

        return $run;
    }

    /** The event id of a recent click by this user (double click), else one per DEDUPE_SECONDS window. */
    private function eventId(Workflow $workflow, Submission $submission, User $actor): string
    {
        $prefix = 'manual:'.$actor->id.':';
        $recent = WorkflowRun::query()->where('workflow_id', $workflow->id)->where('submission_uuid', $submission->uuid)
            ->where('event_id', 'like', $prefix.'%')->where('created_at', '>=', now()->subSeconds(self::DEDUPE_SECONDS))
            ->orderByDesc('id')->value('event_id');

        return is_string($recent) ? $recent : $prefix.intdiv(now()->getTimestamp(), self::DEDUPE_SECONDS);
    }
}
