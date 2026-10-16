<?php

declare(strict_types=1);

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Events\SubmissionStatusChanged;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Models\SubmissionEvent;
use App\Domain\Workflows\Engine\WorkflowRunner;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Enums\TaskOutcome;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Domain\Workflows\Tasks\CompleteReviewTask;
use App\Domain\Workflows\Triggers\StartWorkflowsForEvent;
use App\Domain\Workflows\Triggers\SweepUnstartedEvents;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Workflows\FakeHostResolver;
use Tests\Feature\Workflows\WorkflowFixtures as W;

/* M4 review fixes C3, S6, C4, C5: queued trigger start with a sweep, approved → completed after post-approval runs. */

beforeEach(function (): void {
    W::notifier();
    $this->s = W::scenario();
    $this->tenant = app(TenantContext::class);
    $this->reviewer = W::reviewer($this->s->org);
    $this->group = W::group($this->s->org, 'Prüfer', $this->reviewer);
    FakeHostResolver::bind();
});

function lifecycleEvents(Submission $submission): Collection
{
    return app(TenantContext::class)->run(test()->s->org, fn () => SubmissionEvent::query()->where('submission_uuid', $submission->uuid)->orderBy('id')->get());
}

function lifecycleEndpoint(): WebhookEndpoint
{
    return app(TenantContext::class)->run(test()->s->org, fn () => WebhookEndpoint::factory()->create(['url' => 'https://hooks.example.com/in']));
}

function lifecycleApprove(Submission $submission): void
{
    app(TenantContext::class)->run(test()->s->org, fn () => app(CompleteReviewTask::class)->handle(W::task(test()->s->org, $submission), test()->reviewer, TaskOutcome::Approved, null));
}

it('starts workflows in a queued job after the transition commits; conditions are evaluated there', function (): void {
    W::workflow($this->s->org, [W::step('notify', ['user_ids' => [$this->s->owner->id], 'channels' => ['database'], 'message' => 'x'])],
        conditions: json_decode('{"==": [{"var": "submission.status"}, "submitted"]}'));
    Queue::fake([StartWorkflowsForEvent::class]);

    $submission = W::submitted($this->s);

    $event = lifecycleEvents($submission)->firstWhere('to_status', 'submitted');
    expect(W::runs($this->s->org))->toHaveCount(0)
        ->and($event->workflows_processed_at)->toBeNull();
    Queue::assertPushed(StartWorkflowsForEvent::class, fn (StartWorkflowsForEvent $job): bool => $job->submissionEventId === $event->id
        && $job->tries === 5 && $job->backoff() === [10, 30, 90, 270]);

    $job = Queue::pushed(StartWorkflowsForEvent::class)->first();
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);   // a retry or duplicate finds the run of this event

    expect(W::runs($this->s->org))->toHaveCount(1)
        ->and(lifecycleEvents($submission)->firstWhere('to_status', 'submitted')->workflows_processed_at)->not->toBeNull();
});

it('sweeps status changes whose workflow start was lost, so submitted → completed is not lost', function (): void {
    $events = Event::getFacadeRoot();
    Event::fake([SubmissionStatusChanged::class]);
    $submission = W::submitted($this->s);
    Event::swap($events);
    expect(W::fresh($this->s, $submission)->status)->toBe(SubmissionStatus::Submitted);

    expect(app(SweepUnstartedEvents::class)->handle(CarbonImmutable::now()))->toBe(0);   // too fresh to call lost

    expect(app(SweepUnstartedEvents::class)->handle(CarbonImmutable::now()->addMinutes(11)))->toBe(1)
        ->and(W::fresh($this->s, $submission)->status)->toBe(SubmissionStatus::Completed)
        ->and(lifecycleEvents($submission)->whereNull('workflows_processed_at'))->toHaveCount(0)
        ->and(app(SweepUnstartedEvents::class)->handle(CarbonImmutable::now()->addMinutes(11)))->toBe(0);
});

it('keeps an approved submission open until the runs started by the approval finish (post-approval steps)', function (): void {
    Http::fake(['hooks.example.com/*' => Http::sequence()->push('down', 503)->push('ok', 200)]);
    W::workflow($this->s->org, [W::reviewStep($this->group)]);
    W::workflow($this->s->org, [W::step('webhook', ['endpoint_id' => lifecycleEndpoint()->id])], TriggerType::SubmissionApproved);
    $submission = W::submitted($this->s);

    lifecycleApprove($submission);

    $approvalRun = W::runs($this->s->org)->firstWhere('trigger', 'submission.approved');
    expect($approvalRun?->status)->toBe(RunStatus::Failed)
        ->and(W::runs($this->s->org)->firstWhere('trigger', 'submission.submitted')?->status)->toBe(RunStatus::Completed)
        ->and(W::fresh($this->s, $submission)->status)->toBe(SubmissionStatus::Approved);

    $this->tenant->run($this->s->org, fn () => app(WorkflowRunner::class)->retry($approvalRun, $this->s->owner));

    expect(W::runs($this->s->org)->firstWhere('trigger', 'submission.approved')?->status)->toBe(RunStatus::Completed)
        ->and(W::fresh($this->s, $submission)->status)->toBe(SubmissionStatus::Completed)
        ->and(lifecycleEvents($submission)->pluck('to_status')->all())->toBe(['submitted', 'in_review', 'approved', 'completed']);
});

it('completes after a failed post-approval step of the review run succeeds on manual retry', function (): void {
    Http::fake(['hooks.example.com/*' => Http::sequence()->push('down', 503)->push('ok', 200)]);
    W::workflow($this->s->org, [W::reviewStep($this->group), W::step('webhook', ['endpoint_id' => lifecycleEndpoint()->id])]);
    $submission = W::submitted($this->s);

    lifecycleApprove($submission);

    $run = W::runs($this->s->org)->sole();
    expect($run->status)->toBe(RunStatus::Failed)->and(W::fresh($this->s, $submission)->status)->toBe(SubmissionStatus::Approved);

    $this->tenant->run($this->s->org, fn () => app(WorkflowRunner::class)->retry($run, $this->s->owner));

    expect(W::runs($this->s->org)->sole()->status)->toBe(RunStatus::Completed)
        ->and(W::fresh($this->s, $submission)->status)->toBe(SubmissionStatus::Completed);
});
