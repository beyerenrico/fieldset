<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Events\SubmissionStatusChanged;
use App\Domain\Submissions\Models\SubmissionEvent;
use App\Domain\Workflows\Engine\RunWorkflowStep;
use App\Domain\Workflows\Engine\SweepStalledRuns;
use App\Domain\Workflows\Engine\WorkflowRunner;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Enums\StepStatus;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Triggers\RunScheduledWorkflows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Workflows\FakeHostResolver;
use Tests\Feature\Workflows\WorkflowFixtures as W;

/* M4-T01: event matching, conditions, snapshots, sequential steps, idempotent runs, failures, retry. */

beforeEach(function (): void {
    $this->notifier = W::notifier();
    $this->s = W::scenario();
    $this->tenant = app(TenantContext::class);
    FakeHostResolver::bind();
});

function engineEndpoint(): WebhookEndpoint
{
    return app(TenantContext::class)->run(test()->s->org, fn () => WebhookEndpoint::factory()->create(['url' => 'https://hooks.example.test/in']));
}

it('starts a run on submit, stores the definition snapshot, runs the steps in order and completes the submission', function (): void {
    Http::fake(['hooks.example.test/*' => Http::response('ok', 200)]);
    $endpoint = engineEndpoint();
    $workflow = W::workflow($this->s->org, [
        W::step('notify', ['user_ids' => [$this->s->owner->id], 'message' => 'Neu']),
        W::step('webhook', ['endpoint_id' => $endpoint->id]),
    ]);

    $submission = W::submitted($this->s);

    $run = W::runs($this->s->org)->sole();
    expect($run->workflow_id)->toBe($workflow->id)
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and($run->trigger)->toBe('submission.submitted')
        ->and($run->submission_uuid)->toBe($submission->uuid)
        ->and($run->definition_snapshot['steps'])->toHaveCount(2)
        ->and($run->stepRuns->pluck('status')->all())->toBe([StepStatus::Completed, StepStatus::Completed])
        ->and($run->stepRuns[0]->attempts)->toBe(1)
        ->and($run->stepRuns[1]->output['status'])->toBe(200)
        ->and($submission->status)->toBe(SubmissionStatus::Completed)
        ->and($this->notifier->recipientsOf(NotificationEvent::WorkflowNotice))->toBe([$this->s->owner->id]);

    // The snapshot is what ran: editing the workflow later does not change it.
    $this->tenant->run($this->s->org, fn () => $workflow->forceFill(['steps' => []])->save());
    expect(W::runs($this->s->org)->sole()->definition_snapshot['steps'])->toHaveCount(2);
});

it('moves a submission without matching workflows to completed', function (): void {
    $submission = W::submitted($this->s);

    expect($submission->status)->toBe(SubmissionStatus::Completed)
        ->and(W::runs($this->s->org))->toHaveCount(0);
});

it('keeps the submission submitted when completion without review is switched off', function (): void {
    config(['workflows.complete_without_review' => false]);

    expect(W::submitted($this->s)->status)->toBe(SubmissionStatus::Submitted);
});

it('creates one run per (workflow, submission, event id): a duplicate event runs nothing twice', function (): void {
    W::workflow($this->s->org, [W::step('send_email', ['to_addresses' => ['office@example.com'], 'subject' => 'Neu {{ submission.number }}'])]);
    $submission = W::submitted($this->s);
    $row = $this->tenant->run($this->s->org, fn () => SubmissionEvent::query()->where('submission_uuid', $submission->uuid)->where('to_status', 'submitted')->sole());

    event(new SubmissionStatusChanged($row->id, $submission->uuid, $this->s->org->id, 'draft', 'submitted', $this->s->worker->id));
    event(new SubmissionStatusChanged($row->id, $submission->uuid, $this->s->org->id, 'draft', 'submitted', $this->s->worker->id));

    expect(W::runs($this->s->org))->toHaveCount(1)
        ->and($this->tenant->run($this->s->org, fn () => MailMessage::query()->count()))->toBe(1);
});

it('evaluates JsonLogic conditions over the submission context', function (): void {
    $match = W::workflow($this->s->org, [W::step('notify', ['user_ids' => [$this->s->owner->id], 'message' => 'x'])], conditions: json_decode(
        '{"and": [{"==": [{"var": "submission.data.service_type"}, "repair"]}, {"==": [{"var": "submission.form_key"}, "'.$this->s->form->key.'"]}, {"==": [{"var": "trigger.to_status"}, "submitted"]}]}',
    ));
    W::workflow($this->s->org, [W::step('notify', ['user_ids' => [$this->s->owner->id], 'message' => 'x'])], conditions: json_decode(
        '{"==": [{"var": "submission.data.service_type"}, "maintenance"]}',
    ));

    W::submitted($this->s);

    expect(W::runs($this->s->org)->pluck('workflow_id')->all())->toBe([$match->id]);
});

it('narrows triggers to forms and to a target status', function (): void {
    $notify = [W::step('notify', ['user_ids' => [$this->s->owner->id], 'message' => 'x'])];
    W::workflow($this->s->org, $notify, TriggerType::SubmissionSubmitted, ['form_ids' => [$this->s->form->id + 1000]]);
    $completed = W::workflow($this->s->org, $notify, TriggerType::SubmissionStatusChanged, ['to_status' => 'completed']);
    W::workflow($this->s->org, $notify, TriggerType::SubmissionStatusChanged, ['to_status' => 'void']);
    $any = W::workflow($this->s->org, $notify, TriggerType::SubmissionStatusChanged);

    W::submitted($this->s);

    // draft→submitted and submitted→completed: "any" runs twice, "completed" once, the others never.
    expect(W::runs($this->s->org)->pluck('workflow_id')->sort()->values()->all())->toBe([$completed->id, $any->id, $any->id]);
});

it('ignores inactive workflows', function (): void {
    $workflow = W::workflow($this->s->org, [W::step('notify', ['user_ids' => [$this->s->owner->id], 'message' => 'x'])]);
    $this->tenant->run($this->s->org, fn () => $workflow->forceFill(['active' => false])->save());

    W::submitted($this->s);

    expect(W::runs($this->s->org))->toHaveCount(0);
});

it('retries with exponential backoff and records each failed attempt on the step', function (): void {
    Http::fake(['hooks.example.test/*' => Http::response('down', 503)]);
    W::workflow($this->s->org, [W::step('webhook', ['endpoint_id' => engineEndpoint()->id])]);

    W::submitted($this->s);   // the sync queue fails the job at its first error

    $step = W::runs($this->s->org)->sole()->stepRuns[0];
    expect(new RunWorkflowStep(1, 0, 1)->tries)->toBe(5)
        ->and(new RunWorkflowStep(1, 0, 1)->backoff())->toBe([10, 30, 90, 270])
        ->and(collect($step->log)->pluck('level')->all())->toContain('warning')
        ->and($step->log[0]['message'])->toContain('503');
});

it('makes failures visible, audits them and notifies owners and admins only', function (): void {
    Http::fake(['hooks.example.test/*' => Http::response('down', 503)]);
    $admin = memberOf($this->s->org, SystemRole::Admin);
    memberOf($this->s->org, SystemRole::Designer);
    W::workflow($this->s->org, [W::step('webhook', ['endpoint_id' => engineEndpoint()->id]), W::step('set_status', ['status' => 'completed'])]);

    $submission = W::submitted($this->s);

    $run = W::runs($this->s->org)->sole();
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error)->toContain('503')
        ->and($run->stepRuns->pluck('status')->all())->toBe([StepStatus::Failed, StepStatus::Pending])
        ->and($submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($this->tenant->run($this->s->org, fn () => Activity::query()->where('event', 'workflow.run_failed')->count()))->toBe(1)
        ->and($this->notifier->recipientsOf(NotificationEvent::WorkflowFailed))->toEqualCanonicalizing([$this->s->owner->id, $admin->id]);
});

it('retries a failed run manually from the failed step', function (): void {
    Http::fake(['hooks.example.test/*' => Http::sequence()->push('down', 503)->push('ok', 200)]);
    W::workflow($this->s->org, [W::step('notify', ['user_ids' => [$this->s->owner->id], 'message' => 'x']), W::step('webhook', ['endpoint_id' => engineEndpoint()->id])]);
    W::submitted($this->s);
    $run = W::runs($this->s->org)->sole();
    expect($run->status)->toBe(RunStatus::Failed)->and($run->current_step)->toBe(1);

    $this->tenant->run($this->s->org, fn () => app(WorkflowRunner::class)->retry($run, $this->s->owner));

    $run = W::runs($this->s->org)->sole();
    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->stepRuns[0]->attempts)->toBe(1)           // the done step did not run again
        ->and($run->stepRuns[1]->attempts)->toBe(2)
        ->and($this->notifier->sentFor(NotificationEvent::WorkflowNotice))->toHaveCount(1)
        ->and($this->tenant->run($this->s->org, fn () => Activity::query()->where('event', 'workflow.run_retried')->count()))->toBe(1);
});

it('ignores duplicate and stale step jobs', function (): void {
    W::workflow($this->s->org, [W::step('notify', ['user_ids' => [$this->s->owner->id], 'message' => 'x'])]);
    W::submitted($this->s);
    $run = W::runs($this->s->org)->sole();

    app()->call([new RunWorkflowStep($run->id, 0, $this->s->org->id), 'handle']);
    app()->call([new RunWorkflowStep($run->id, 5, $this->s->org->id), 'handle']);

    expect(W::runs($this->s->org)->sole()->stepRuns[0]->attempts)->toBe(1)
        ->and($this->notifier->sentFor(NotificationEvent::WorkflowNotice))->toHaveCount(1);
});

it('starts scheduled workflows once per due minute, in the organization time zone', function (): void {
    $this->s->org->forceFill(['timezone' => 'Europe/Berlin'])->save();
    W::workflow($this->s->org, [W::step('notify', ['user_ids' => [$this->s->owner->id], 'message' => 'Tagesbericht'])], TriggerType::Schedule, ['cron' => '0 7 * * *']);
    $at = CarbonImmutable::parse('2026-10-19 07:00:20', 'Europe/Berlin');

    $this->artisan('workflows:tick')->assertSuccessful();
    expect(W::runs($this->s->org))->toHaveCount(0);   // not due now

    $first = app(RunScheduledWorkflows::class)->handle($at);
    $second = app(RunScheduledWorkflows::class)->handle($at->addSeconds(30));
    $run = W::runs($this->s->org)->sole();
    expect($first)->toBe(1)->and($second)->toBe(0)
        ->and($run->submission_uuid)->toBeNull()
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and(app(RunScheduledWorkflows::class)->handle($at->addHour()))->toBe(0);
});

it('fires form.published with the new version and no submission', function (): void {
    W::workflow($this->s->org, [W::step('notify', ['user_ids' => [$this->s->owner->id], 'message' => 'Neue Version'])], TriggerType::FormPublished);

    $version = $this->tenant->run($this->s->org, fn () => app(PublishForm::class)->handle($this->s->form, 'v2', $this->s->owner));

    $run = W::runs($this->s->org)->sole();
    expect($run->submission_uuid)->toBeNull()
        ->and($run->event_id)->toBe('form_version:'.$version->id)
        ->and($run->context['trigger']['form_id'])->toBe($this->s->form->id)
        ->and($run->status)->toBe(RunStatus::Completed);
});

it('fails steps that need a submission on runs without one', function (): void {
    W::workflow($this->s->org, [W::step('set_status', ['status' => 'completed'])], TriggerType::FormPublished);

    $this->tenant->run($this->s->org, fn () => app(PublishForm::class)->handle($this->s->form, 'v2', $this->s->owner));

    expect(W::runs($this->s->org)->sole()->error)->toBe(__('workflows.errors.needs_submission'));
});

it('re-queues runs that stopped making progress', function (): void {
    $run = $this->tenant->run($this->s->org, fn () => WorkflowRun::factory()->create([
        'status' => RunStatus::Running, 'definition_snapshot' => ['name' => 'x', 'steps' => [W::step('notify', ['user_ids' => [$this->s->owner->id], 'message' => 'x'])]],
    ]));
    $this->tenant->run($this->s->org, fn () => WorkflowRun::query()->whereKey($run->id)->update(['updated_at' => now()->subHour()]));
    Queue::fake();

    expect(app(SweepStalledRuns::class)->handle(CarbonImmutable::now()))->toBe(1)
        ->and(app(SweepStalledRuns::class)->handle(CarbonImmutable::now()))->toBe(0);
    Queue::assertPushed(RunWorkflowStep::class, fn (RunWorkflowStep $job): bool => $job->runId === $run->id && $job->stepIndex === 0);
});
