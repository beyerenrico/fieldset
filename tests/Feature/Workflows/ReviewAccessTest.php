<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Actions\SaveRole;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Submissions\Actions\TransitionSubmission;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Support\SubmissionVisibility;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Enums\StepStatus;
use App\Domain\Workflows\Enums\TaskOutcome;
use App\Domain\Workflows\Enums\TaskStatus;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Tasks\CompleteReviewTask;
use App\Domain\Workflows\Tasks\SendOverdueReminders;
use App\Domain\Workflows\Tasks\TaskAccess;
use App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission;
use App\Http\Api\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Workflows\WorkflowFixtures as W;

/* M4 review fixes S1, C1, C2, C6: visibility through open tasks only, cancelled tasks, recipients who can act, clean 409s. */

beforeEach(function (): void {
    $this->notifier = W::notifier();
    $this->s = W::scenario();
    $this->tenant = app(TenantContext::class);
    $this->reviewer = W::reviewer($this->s->org);
});

function accessCanView(User $user, Submission $submission): bool
{
    return app(TenantContext::class)->run(test()->s->org, fn (): bool => app(SubmissionVisibility::class)->canView($user, $submission));
}

function accessComplete(User $user, string $outcome, int $taskId, ?string $comment = null): TestResponse
{
    return test()->withHeaders([...apiHeaders($user, test()->s->org), 'Idempotency-Key' => (string) Str::uuid()])
        ->postJson("/api/v1/tasks/{$taskId}/complete", ['outcome' => $outcome, 'comment' => $comment]);
}

it('never grants visibility on assignment alone: a designer cannot read submissions by assigning a review to themselves', function (): void {
    $designer = memberOf($this->s->org, SystemRole::Designer);
    W::workflow($this->s->org, [W::step('request_review', ['assignee_type' => 'user', 'assignee_user_id' => $designer->id])]);
    $submission = W::submitted($this->s);
    $task = W::task($this->s->org, $submission);

    expect($task->assignee_user_id)->toBe($designer->id)
        ->and(accessCanView($designer, $submission))->toBeFalse()
        ->and($this->tenant->run($this->s->org, fn (): bool => app(TaskAccess::class)->canComplete($designer, $task)))->toBeFalse();
    $this->withHeaders(apiHeaders($designer, $this->s->org))->getJson('/api/v1/submissions/'.$submission->uuid)->assertNotFound();
    $this->withHeaders(apiHeaders($designer, $this->s->org))->getJson('/api/v1/tasks')->assertOk()->assertJsonPath('meta.total', 0);
    accessComplete($designer, 'approve', $task->id)->assertNotFound();
});

it('grants view_assigned holders visibility while the task is open; afterwards only the decider keeps it', function (): void {
    $colleague = W::reviewer($this->s->org);
    $group = W::group($this->s->org, 'Prüfer', $this->reviewer, $colleague);
    W::workflow($this->s->org, [W::reviewStep($group)]);
    $submission = W::submitted($this->s);

    expect(accessCanView($this->reviewer, $submission))->toBeTrue()->and(accessCanView($colleague, $submission))->toBeTrue();

    $this->tenant->run($this->s->org, fn () => app(CompleteReviewTask::class)->handle(W::task($this->s->org, $submission), $this->reviewer, TaskOutcome::Approved, null));

    expect(accessCanView($this->reviewer, $submission))->toBeTrue()
        ->and(accessCanView($colleague, $submission))->toBeFalse();
    $this->withHeaders(apiHeaders($colleague, $this->s->org))->getJson('/api/v1/submissions/'.$submission->uuid)->assertNotFound();
});

it('cancels open review tasks and ends their waiting run when the submission leaves review another way', function (): void {
    $group = W::group($this->s->org, 'Prüfer', $this->reviewer);
    W::workflow($this->s->org, [W::reviewStep($group), W::step('notify', ['user_ids' => [$this->s->owner->id], 'channels' => ['database'], 'message' => 'x'])]);
    $submission = W::submitted($this->s);
    $task = W::task($this->s->org, $submission);

    $this->tenant->run($this->s->org, fn () => DB::transaction(fn () => app(TransitionSubmission::class)->handle(
        Submission::query()->lockForUpdate()->findOrFail($submission->uuid), SubmissionStatus::ChangesRequested, $this->s->owner, 'anders',
    )));

    $run = W::runs($this->s->org)->sole();
    expect($task->refresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($run->status)->toBe(RunStatus::Cancelled)
        ->and($run->stepRuns->pluck('status')->all())->toBe([StepStatus::Cancelled, StepStatus::Pending])
        ->and(accessCanView($this->reviewer, $submission))->toBeFalse();
    accessComplete($this->reviewer, 'approve', $task->id)->assertStatus(409)->assertJsonPath('error.code', 'task_closed');
    $this->withHeaders(apiHeaders($this->reviewer, $this->s->org))->getJson('/api/v1/tasks')->assertOk()->assertJsonPath('meta.total', 0);
});

it('notifies only group members who can act on the review, also for overdue reminders', function (): void {
    $workerInGroup = memberOf($this->s->org, SystemRole::FieldWorker);
    $group = W::group($this->s->org, 'Prüfer', $this->reviewer, $workerInGroup);   // both members before the submit
    W::workflow($this->s->org, [W::reviewStep($group, 1)]);

    W::submitted($this->s);
    app(SendOverdueReminders::class)->handle(CarbonImmutable::now()->addHours(2));

    $sent = $this->notifier->sentFor(NotificationEvent::ReviewAssigned);
    expect($sent)->toHaveCount(2)
        ->and($sent[0]['recipients'])->toBe([$this->reviewer->id])
        ->and($sent[1]['message']->titleKey)->toBe('workflows.notifications.review_overdue.title')
        ->and($sent[1]['recipients'])->toBe([$this->reviewer->id]);
});

it('links review notifications to the admin inbox for admin users and to the Field App for others', function (): void {
    // A reviewer without admin access (custom role: review + view_assigned, no admin.access).
    $role = $this->tenant->run($this->s->org, fn () => app(SaveRole::class)->handle(
        $this->s->owner, $this->s->org, null, 'Prüfer mobil',
        Permission::query()->whereIn('name', ['submissions.review', 'submissions.view_assigned', 'forms.fill'])->pluck('id')->all(),
    ));
    $mobile = memberOf($this->s->org, SystemRole::FieldWorker);
    app(AddMember::class)->handle($this->s->org, $mobile, [SystemRole::FieldWorker, $role->name]);
    $group = W::group($this->s->org, 'Prüfer', $this->reviewer, $mobile);
    W::workflow($this->s->org, [W::reviewStep($group)]);

    $submission = W::submitted($this->s);

    $sent = collect($this->notifier->sentFor(NotificationEvent::ReviewAssigned));
    $admin = $sent->first(fn (array $e): bool => $e['recipients'] === [$this->reviewer->id]);
    $field = $sent->first(fn (array $e): bool => $e['recipients'] === [$mobile->id]);
    expect($sent)->toHaveCount(2)
        ->and($admin['message']->url)->toContain('/admin/')->toContain($submission->uuid)
        ->and($field['message']->url)->toBe('/app/submissions/'.$submission->uuid);
});

it('answers a decision on a failed run with 409 review_blocked and keeps the task for after the retry', function (): void {
    $group = W::group($this->s->org, 'Prüfer', $this->reviewer);
    W::workflow($this->s->org, [W::reviewStep($group)]);
    $submission = W::submitted($this->s);
    $task = W::task($this->s->org, $submission);
    $this->tenant->run($this->s->org, fn () => WorkflowRun::query()->whereKey($task->workflow_run_id)->update(['status' => RunStatus::Failed->value]));

    accessComplete($this->reviewer, 'approve', $task->id)->assertStatus(409)->assertJsonPath('error.code', 'review_blocked');

    expect($task->refresh()->status)->toBe(TaskStatus::Open)
        ->and(W::fresh($this->s, $submission)->status)->toBe(SubmissionStatus::InReview);
    auth()->shouldUse('web');   // back from the API (Sanctum) guard to the panel's
    actingInAdmin($this->s->owner, $this->s->org);
    Livewire::test(ViewSubmission::class, ['record' => $submission->uuid])
        ->callAction('approve', ['comment' => null])
        ->assertNotified(__('workflows.tasks.errors.blocked'));
});

it('closes a stale task whose submission already left review and answers task_closed', function (): void {
    $group = W::group($this->s->org, 'Prüfer', $this->reviewer);
    W::workflow($this->s->org, [W::reviewStep($group)]);
    $submission = W::submitted($this->s);
    $task = W::task($this->s->org, $submission);
    // Simulate a lost cancellation: the status moved on without the listener having run.
    $this->tenant->run($this->s->org, fn () => Submission::query()->whereKey($submission->uuid)->update(['status' => SubmissionStatus::Approved->value]));

    expect(fn () => $this->tenant->run($this->s->org, fn () => app(CompleteReviewTask::class)->handle($task, $this->reviewer, TaskOutcome::Approved, null)))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('task_closed'));
    expect($task->refresh()->status)->toBe(TaskStatus::Cancelled);
});
