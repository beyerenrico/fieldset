<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\SyncMemberGroups;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\SubmissionEvent;
use App\Domain\Submissions\Support\SubmissionVisibility;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Enums\StepStatus;
use App\Domain\Workflows\Enums\TaskOutcome;
use App\Domain\Workflows\Enums\TaskStatus;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Tasks\CompleteReviewTask;
use App\Domain\Workflows\Tasks\SendOverdueReminders;
use App\Domain\Workflows\Tasks\TaskAccess;
use App\Http\Api\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Workflows\WorkflowFixtures as W;
use Tests\Support\FieldAppApi;

/* M4-T04: request_review creates a task, approve resumes, reject requests changes, resubmit starts a new run. */

beforeEach(function (): void {
    $this->notifier = W::notifier();
    $this->s = W::scenario();
    $this->tenant = app(TenantContext::class);
    $this->reviewer = W::reviewer($this->s->org);
    $this->group = W::group($this->s->org, 'Prüfer', $this->reviewer);
    $this->workflow = W::workflow($this->s->org, [
        W::reviewStep($this->group, 48),
        W::step('notify', ['user_ids' => [$this->s->owner->id], 'message' => 'Freigegeben']),
    ]);
});

function decide(Task $task, User $user, TaskOutcome $outcome, ?string $comment = null): Task
{
    return app(TenantContext::class)->run(test()->s->org, fn () => app(CompleteReviewTask::class)->handle($task, $user, $outcome, $comment));
}

it('creates a review task for the group, sets in_review, notifies the group and pauses the run', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 08:00:00'));
    $submission = W::submitted($this->s);

    $task = W::task($this->s->org, $submission);
    $run = W::runs($this->s->org)->sole();
    expect($submission->status)->toBe(SubmissionStatus::InReview)
        ->and($task->status)->toBe(TaskStatus::Open)
        ->and($task->assignee_group_id)->toBe($this->group->id)
        ->and($task->due_at?->toDateTimeString())->toBe('2026-10-21 08:00:00')
        ->and($task->workflow_run_id)->toBe($run->id)
        ->and($run->status)->toBe(RunStatus::Waiting)
        ->and($run->stepRuns->pluck('status')->all())->toBe([StepStatus::Waiting, StepStatus::Pending])
        ->and($this->notifier->recipientsOf(NotificationEvent::ReviewAssigned))->toBe([$this->reviewer->id])
        ->and($this->notifier->sentFor(NotificationEvent::ReviewAssigned)[0]['message']->url)->toBe('/app/submissions/'.$submission->uuid);
});

it('approves: submission approved, run continues and completes, submission completed, submitter notified', function (): void {
    $submission = W::submitted($this->s);

    decide(W::task($this->s->org, $submission), $this->reviewer, TaskOutcome::Approved);

    $run = W::runs($this->s->org)->sole();
    $task = W::task($this->s->org, $submission);
    $statuses = $this->tenant->run($this->s->org, fn () => SubmissionEvent::query()->where('submission_uuid', $submission->uuid)->orderBy('id')->pluck('to_status')->all());
    expect($task->status)->toBe(TaskStatus::Completed)
        ->and($task->outcome)->toBe(TaskOutcome::Approved)
        ->and($task->completed_by)->toBe($this->reviewer->id)
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and($run->stepRuns[0]->output['outcome'])->toBe('approved')
        ->and($statuses)->toBe(['submitted', 'in_review', 'approved', 'completed'])
        ->and(W::fresh($this->s, $submission)->status)->toBe(SubmissionStatus::Completed)
        ->and($this->notifier->recipientsOf(NotificationEvent::SubmissionApproved))->toBe([$this->s->worker->id])
        ->and($this->notifier->recipientsOf(NotificationEvent::WorkflowNotice))->toBe([$this->s->owner->id]);
});

it('rejects with a required comment: changes_requested, comment on the event, run ends, submitter notified', function (): void {
    $submission = W::submitted($this->s);
    $task = W::task($this->s->org, $submission);

    expect(fn () => decide($task, $this->reviewer, TaskOutcome::Rejected, '  '))->toThrow(ValidationException::class);

    decide($task, $this->reviewer, TaskOutcome::Rejected, 'Seriennummer fehlt');

    $event = $this->tenant->run($this->s->org, fn () => SubmissionEvent::query()->where('submission_uuid', $submission->uuid)->orderByDesc('id')->first());
    $run = W::runs($this->s->org)->sole();
    expect(W::fresh($this->s, $submission)->status)->toBe(SubmissionStatus::ChangesRequested)
        ->and($event?->to_status)->toBe('changes_requested')
        ->and($event?->comment)->toBe('Seriennummer fehlt')
        ->and($event?->actor_id)->toBe($this->reviewer->id)
        ->and($run->status)->toBe(RunStatus::Rejected)
        ->and($run->stepRuns->pluck('status')->all())->toBe([StepStatus::Completed, StepStatus::Pending])
        ->and($this->notifier->recipientsOf(NotificationEvent::ChangesRequested))->toBe([$this->s->worker->id])
        ->and($this->notifier->sentFor(NotificationEvent::ChangesRequested)[0]['message']->params['comment'])->toBe('Seriennummer fehlt')
        ->and($this->notifier->sentFor(NotificationEvent::WorkflowNotice))->toBe([]);
});

it('starts a new run when the worker resubmits after changes were requested (number kept)', function (): void {
    $submission = W::submitted($this->s);
    $number = $submission->number;
    decide(W::task($this->s->org, $submission), $this->reviewer, TaskOutcome::Rejected, 'Bitte ergänzen');
    $fresh = W::fresh($this->s, $submission);

    FieldAppApi::submit($this->s, $this->s->worker, $submission->uuid, $fresh->revision)->assertOk()
        ->assertJsonPath('data.submission.number', $number)
        ->assertJsonPath('data.submission.status', 'submitted');   // workflows run after the submit commits

    $runs = W::runs($this->s->org);
    expect(W::fresh($this->s, $submission)->status)->toBe(SubmissionStatus::InReview)
        ->and($runs)->toHaveCount(2)
        ->and($runs[0]->event_id)->not->toBe($runs[1]->event_id)
        ->and($runs[1]->status)->toBe(RunStatus::Waiting)
        ->and(W::task($this->s->org, $submission)->status)->toBe(TaskStatus::Open);
});

it('completes a task only once', function (): void {
    $submission = W::submitted($this->s);
    $task = W::task($this->s->org, $submission);
    decide($task, $this->reviewer, TaskOutcome::Approved);

    expect(fn () => decide($task, $this->s->owner, TaskOutcome::Rejected, 'zu spät'))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('task_closed')->and($e->status)->toBe(409));
});

it('lets reviewers (submissions.review) or the assignee user who can see the submission complete', function (): void {
    $submission = W::submitted($this->s);
    $task = W::task($this->s->org, $submission);
    $workerInGroup = memberOf($this->s->org, SystemRole::FieldWorker);
    $this->tenant->run($this->s->org, fn () => app(SyncMemberGroups::class)->handle($this->s->org, $workerInGroup, [$this->group->id]));
    $otherReviewer = W::reviewer($this->s->org);
    $access = fn (User $user): bool => $this->tenant->run($this->s->org, fn (): bool => app(TaskAccess::class)->canComplete($user, $task));
    $listed = fn (User $user): array => $this->tenant->run($this->s->org, fn (): array => app(TaskAccess::class)->actionable(Task::query(), $user)->pluck('id')->all());

    expect($access($this->reviewer))->toBeTrue()
        ->and($listed($this->reviewer))->toBe([$task->id])
        ->and($access($this->s->owner))->toBeTrue()                     // review + sees everything
        ->and($listed($this->s->owner))->toBe([])                       // but it is not assigned to them
        ->and($access($workerInGroup))->toBeFalse()                     // group member without submissions.review
        ->and($listed($workerInGroup))->toBe([])
        ->and($access($otherReviewer))->toBeFalse();                    // cannot see the submission

    $this->tenant->run($this->s->org, fn () => $task->forceFill(['assignee_group_id' => null, 'assignee_user_id' => $workerInGroup->id])->save());
    expect($access($workerInGroup))->toBeTrue()->and($listed($workerInGroup))->toBe([$task->id]);
});

it('lets assigned reviewers see the submission (ADR 0030 widening)', function (): void {
    $submission = W::submitted($this->s);

    expect($this->tenant->run($this->s->org, fn (): bool => app(SubmissionVisibility::class)->canView($this->reviewer, $submission)))->toBeTrue()
        ->and($this->tenant->run($this->s->org, fn (): bool => app(SubmissionVisibility::class)->canView(W::reviewer($this->s->org), $submission)))->toBeFalse();
});

it('reminds assignees once when a task is overdue', function (): void {
    $submission = W::submitted($this->s);
    $later = CarbonImmutable::now()->addHours(49);

    expect(app(SendOverdueReminders::class)->handle(CarbonImmutable::now()))->toBe(0)
        ->and(app(SendOverdueReminders::class)->handle($later))->toBe(1)
        ->and(app(SendOverdueReminders::class)->handle($later->addHour()))->toBe(0);

    $reminders = array_filter($this->notifier->sentFor(NotificationEvent::ReviewAssigned), fn (array $e): bool => $e['message']->titleKey === 'workflows.notifications.review_overdue.title');
    expect(array_values($reminders))->toHaveCount(1)
        ->and(array_values($reminders)[0]['recipients'])->toBe([$this->reviewer->id])
        ->and(W::task($this->s->org, $submission)->reminded_at)->not->toBeNull();
});

it('fails the review step when the submission is no longer awaiting review', function (): void {
    $workflow = W::workflow($this->s->org, [W::step('set_status', ['status' => 'completed']), W::reviewStep($this->group)]);
    $this->tenant->run($this->s->org, fn () => $this->workflow->forceFill(['active' => false])->save());

    W::submitted($this->s);

    $run = W::runs($this->s->org)->firstWhere('workflow_id', $workflow->id);
    expect($run?->status)->toBe(RunStatus::Failed)
        ->and($run?->error)->toContain(SubmissionStatus::Completed->label());
});
