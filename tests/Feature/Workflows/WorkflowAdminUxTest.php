<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Actions\TransitionSubmission;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Enums\TaskOutcome;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Tasks\CompleteReviewTask;
use App\Domain\Workflows\Triggers\StartManualWorkflow;
use App\Filament\Admin\Pages\MyTasks;
use App\Filament\Admin\Resources\Workflows\Pages\EditWorkflow;
use App\Filament\Admin\Resources\Workflows\RelationManagers\RunsRelationManager;
use App\Filament\Admin\Resources\Workflows\Support\WorkflowOptions;
use App\Filament\Admin\Resources\Workflows\WebhookEndpoints\Pages\ManageWebhookEndpoints;
use App\Http\Api\ApiException;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Feature\Workflows\WorkflowFixtures as W;

/* M4 review fixes C7, U1, U3, U6: manual double click, can_edit per viewer, admin time zones and labels, translated errors. */

beforeEach(function (): void {
    W::notifier();
    $this->s = W::scenario();
    $this->tenant = app(TenantContext::class);
    $this->reviewer = W::reviewer($this->s->org);
    $this->group = W::group($this->s->org, 'Prüfer', $this->reviewer);
});

it('starts a manual workflow once for a double click, and again after a short pause', function (): void {
    $manual = W::workflow($this->s->org, [W::step('notify', ['user_ids' => [$this->s->owner->id], 'channels' => ['database'], 'message' => 'x'])], TriggerType::Manual);
    $submission = W::submitted($this->s);
    $start = fn (): ?WorkflowRun => $this->tenant->run($this->s->org, fn () => app(StartManualWorkflow::class)->handle($manual, $submission, $this->s->owner));

    $first = $start();
    $this->travel(3)->seconds();
    $second = $start();
    $this->travel(11)->seconds();
    $third = $start();

    expect($second?->id)->toBe($first?->id)
        ->and($third?->id)->not->toBe($first?->id)
        ->and(W::runs($this->s->org)->where('workflow_id', $manual->id))->toHaveCount(2)
        ->and($this->tenant->run($this->s->org, fn () => Activity::query()->where('event', 'workflow.manual_run')->count()))->toBe(2);
});

it('computes can_edit for the viewer: only the creator while the data is editable', function (): void {
    W::workflow($this->s->org, [W::reviewStep($this->group)]);
    $submission = W::submitted($this->s);
    $this->tenant->run($this->s->org, fn () => app(CompleteReviewTask::class)
        ->handle(W::task($this->s->org, $submission), $this->reviewer, TaskOutcome::Rejected, 'Bitte ergänzen'));

    $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))->getJson('/api/v1/submissions/'.$submission->uuid)
        ->assertOk()->assertJsonPath('data.submission.can_edit', true);
    $this->withHeaders(apiHeaders($this->s->owner, $this->s->org))->getJson('/api/v1/submissions/'.$submission->uuid)
        ->assertOk()->assertJsonPath('data.submission.status', 'changes_requested')->assertJsonPath('data.submission.can_edit', false);
});

it('words invalid transitions with translated status names', function (): void {
    $submission = W::submitted($this->s);   // completed (no workflow)

    expect(fn () => $this->tenant->run($this->s->org, fn () => app(TransitionSubmission::class)
        ->handle(Submission::query()->findOrFail($submission->uuid), SubmissionStatus::Approved, $this->s->owner)))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('invalid_transition')
            ->and($e->getMessage())->toBe(__('submissions.errors.invalid_transition', ['from' => SubmissionStatus::Completed->label(), 'to' => SubmissionStatus::Approved->label()])));
});

it('shows task due dates in the user time zone and marks overdue tasks with text, not only colour', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 06:00:00', 'UTC'));
    W::workflow($this->s->org, [W::reviewStep($this->group, 2)]);
    W::submitted($this->s);
    $this->reviewer->forceFill(['timezone' => 'Europe/Berlin'])->save();
    actingInAdmin($this->reviewer, $this->s->org);

    Livewire::test(MyTasks::class)->assertOk()->assertSee('10:00:00')->assertDontSee(__('workflows.tasks.overdue'));

    $this->travel(3)->hours();
    Livewire::test(MyTasks::class)->assertSee(__('workflows.tasks.overdue'));
});

it('labels run triggers and shows run times in the display time zone', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 06:00:00', 'UTC'));
    $this->s->org->forceFill(['timezone' => 'Europe/Berlin'])->save();
    $workflow = W::workflow($this->s->org, [W::step('notify', ['user_ids' => [$this->s->owner->id], 'channels' => ['database'], 'message' => 'x'])]);
    W::submitted($this->s);
    actingInAdmin($this->s->owner, $this->s->org);

    Livewire::test(RunsRelationManager::class, ['ownerRecord' => $workflow, 'pageClass' => EditWorkflow::class])->assertOk()
        ->assertSee(TriggerType::SubmissionSubmitted->label())->assertSee('08:00:00');
    $timeline = view('workflows.admin.run-timeline', ['run' => W::runs($this->s->org)->sole()])->render();
    expect($timeline)->toContain('08:00:00')->not->toContain('text-gray-400"');
});

it('explains why a workflow with runs or a referenced webhook endpoint cannot be deleted', function (): void {
    $endpoint = $this->tenant->run($this->s->org, fn () => WebhookEndpoint::factory()->create(['name' => 'ERP', 'active' => false]));
    $workflow = W::workflow($this->s->org, [W::step('webhook', ['endpoint_id' => (string) $endpoint->id])], TriggerType::Manual);
    $unused = W::workflow($this->s->org, [W::step('set_status', ['status' => 'completed'])], TriggerType::Manual);
    $this->tenant->run($this->s->org, fn () => WorkflowRun::factory()->create(['workflow_id' => $workflow->id]));
    actingInAdmin($this->s->owner, $this->s->org);

    expect(WorkflowOptions::endpoints())->toBe([$endpoint->id => 'ERP '.__('workflows.webhooks.inactive_suffix')]);
    Livewire::test(EditWorkflow::class, ['record' => $workflow->getRouteKey()])->assertActionDisabled('delete');
    Livewire::test(EditWorkflow::class, ['record' => $unused->getRouteKey()])->assertActionEnabled('delete');
    Livewire::test(ManageWebhookEndpoints::class)->assertTableActionDisabled('delete', $endpoint);
    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->exists())->toBeTrue();
});

it('shows a generated webhook secret in a copyable field after creating the endpoint', function (): void {
    actingInAdmin($this->s->owner, $this->s->org);

    $page = Livewire::test(ManageWebhookEndpoints::class)
        ->callAction('create', ['name' => 'CRM', 'url' => 'https://crm.example.com/hook', 'secret' => '', 'active' => true])
        ->assertHasNoActionErrors();

    $secret = WebhookEndpoint::query()->sole()->secret;
    $page->assertActionMounted('generatedSecret')->assertActionDataSet(['secret' => $secret]);
});
