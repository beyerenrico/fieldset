<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Enums\TaskStatus;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Domain\Workflows\Models\Workflow;
use App\Filament\Admin\Pages\MyTasks;
use App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Admin\Resources\Workflows\Pages\CreateWorkflow;
use App\Filament\Admin\Resources\Workflows\Pages\EditWorkflow;
use App\Filament\Admin\Resources\Workflows\Pages\ListWorkflows;
use App\Filament\Admin\Resources\Workflows\RelationManagers\RunsRelationManager;
use App\Filament\Admin\Resources\Workflows\WebhookEndpoints\Pages\ManageWebhookEndpoints;
use App\Filament\Admin\Resources\Workflows\WebhookEndpoints\WebhookEndpointResource;
use App\Filament\Admin\Resources\Workflows\WorkflowResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Workflows\FakeHostResolver;
use Tests\Feature\Workflows\WorkflowFixtures as W;

/* M4-T05: workflow resource (Builder steps, conditions), runs timeline + retry, My tasks, inbox review actions, webhooks. */

beforeEach(function (): void {
    W::notifier();
    $this->s = W::scenario();
    $this->designer = memberOf($this->s->org, SystemRole::Designer);
    $this->reviewer = W::reviewer($this->s->org);
    $this->group = W::group($this->s->org, 'Prüfer', $this->reviewer);
});

function reviewWorkflowSteps(int $groupId): array
{
    return [
        ['type' => 'request_review', 'data' => ['assignee_type' => 'group', 'assignee_group_id' => $groupId, 'sla_hours' => 24]],
        ['type' => 'send_email', 'data' => ['to_addresses' => [], 'to_fields' => ['customer.email'], 'to_submitter' => false, 'to_group_ids' => [],
            'subject' => 'Ihr Bericht {{ submission.number }}', 'body' => 'Guten Tag', 'document' => 'attachment']],
    ];
}

it('lets designers create a workflow with Builder steps and JsonLogic conditions', function (): void {
    actingInAdmin($this->designer, $this->s->org);

    Livewire::test(ListWorkflows::class)->assertOk();
    Livewire::test(CreateWorkflow::class)
        ->fillForm([
            'name' => 'Prüfen und an Kunden senden', 'trigger_type' => TriggerType::SubmissionSubmitted->value,
            'form_ids' => [$this->s->form->id], 'conditions' => '{"==": [{"var": "submission.data.service_type"}, "repair"]}',
            'steps' => reviewWorkflowSteps($this->group->id),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $workflow = Workflow::query()->sole();
    expect($workflow->trigger_config)->toBe(['form_ids' => [$this->s->form->id]])
        ->and(array_column($workflow->steps, 'type'))->toBe(['request_review', 'send_email'])
        ->and($workflow->steps[1]['data']['to_fields'])->toBe(['customer.email'])
        ->and(json_encode($workflow->conditions))->toBe('{"==":[{"var":"submission.data.service_type"},"repair"]}')
        ->and($workflow->created_by)->toBe($this->designer->id)
        ->and(Activity::query()->where('subject_type', $workflow->getMorphClass())->where('subject_id', $workflow->id)->where('event', 'created')->count())->toBe(1);
});

it('validates conditions, cron expressions and steps', function (): void {
    actingInAdmin($this->designer, $this->s->org);
    $base = ['name' => 'X', 'steps' => [['type' => 'set_status', 'data' => ['status' => 'completed']]]];

    Livewire::test(CreateWorkflow::class)->fillForm([...$base, 'trigger_type' => 'submission.submitted', 'conditions' => '{not json'])
        ->call('create')->assertHasFormErrors(['conditions']);
    Livewire::test(CreateWorkflow::class)->fillForm([...$base, 'trigger_type' => 'submission.submitted', 'conditions' => '{"bogus_op": [1]}'])
        ->call('create')->assertHasFormErrors(['conditions']);
    Livewire::test(CreateWorkflow::class)->fillForm([...$base, 'trigger_type' => 'schedule', 'cron' => 'every day'])
        ->call('create')->assertHasFormErrors(['cron']);
    Livewire::test(CreateWorkflow::class)->fillForm(['name' => 'X', 'trigger_type' => 'manual', 'steps' => []])
        ->call('create')->assertHasFormErrors(['steps']);

    expect(Workflow::query()->count())->toBe(0);
});

it('edits a workflow and keeps the step configuration', function (): void {
    actingInAdmin($this->designer, $this->s->org);
    $workflow = W::workflow($this->s->org, reviewWorkflowSteps($this->group->id), TriggerType::SubmissionStatusChanged, ['to_status' => 'approved']);

    Livewire::test(EditWorkflow::class, ['record' => $workflow->getRouteKey()])->assertOk()
        ->assertSet('data.to_status', 'approved')
        ->fillForm(['active' => false, 'name' => 'Umbenannt'])
        ->call('save')->assertHasNoFormErrors();

    $workflow->refresh();
    expect($workflow->active)->toBeFalse()->and($workflow->name)->toBe('Umbenannt')
        ->and($workflow->trigger_config)->toBe(['to_status' => 'approved'])
        ->and($workflow->steps[0]['data']['assignee_group_id'])->toBe($this->group->id);
});

it('shows runs with a step timeline and retries a failed run', function (): void {
    FakeHostResolver::bind();
    Http::fake(['hooks.example.com/*' => Http::sequence()->push('down', 503)->push('ok', 200)]);
    $endpoint = app(TenantContext::class)->run($this->s->org, fn () => WebhookEndpoint::factory()->create(['url' => 'https://hooks.example.com/in']));
    $workflow = W::workflow($this->s->org, [W::step('webhook', ['endpoint_id' => $endpoint->id])]);
    W::submitted($this->s);
    $run = W::runs($this->s->org)->sole();
    actingInAdmin($this->designer, $this->s->org);

    Livewire::test(RunsRelationManager::class, ['ownerRecord' => $workflow, 'pageClass' => EditWorkflow::class])->assertOk()
        ->assertCanSeeTableRecords([$run])
        ->callTableAction('timeline', $run)->assertHasNoTableActionErrors()
        ->callTableAction('retry', $run)->assertHasNoTableActionErrors();
    $timeline = app(TenantContext::class)->run($this->s->org, fn (): string => view('workflows.admin.run-timeline', ['run' => $run->load('stepRuns')])->render());
    expect($timeline)->toContain(__('workflows.steps.webhook.label'))->toContain('503')->toContain(__('workflows.step_status.completed'));

    expect($run->refresh()->status)->toBe(RunStatus::Completed);
});

it('lists my open tasks and decides them, a rejection needs a comment', function (): void {
    W::workflow($this->s->org, [W::reviewStep($this->group)]);
    $first = W::submitted($this->s);
    $second = W::submitted($this->s);
    [$taskA, $taskB] = [W::task($this->s->org, $first), W::task($this->s->org, $second)];
    actingInAdmin($this->reviewer, $this->s->org);

    expect(MyTasks::canAccess())->toBeTrue()->and(MyTasks::getNavigationBadge())->toBe('2');
    Livewire::test(MyTasks::class)->assertOk()
        ->assertCanSeeTableRecords([$taskA, $taskB])
        ->callTableAction('approve', $taskA, ['comment' => 'Passt'])->assertHasNoTableActionErrors()
        ->callTableAction('reject', $taskB, ['comment' => ''])->assertHasTableActionErrors(['comment' => 'required']);
    Livewire::test(MyTasks::class)->callTableAction('reject', $taskB, ['comment' => 'Foto fehlt'])->assertHasNoTableActionErrors();

    expect($taskA->refresh()->status)->toBe(TaskStatus::Completed)
        ->and(W::fresh($this->s, $first)->status->value)->toBe('completed')
        ->and(W::fresh($this->s, $second)->status->value)->toBe('changes_requested');
});

it('hides My tasks from users without review rights or assignments', function (): void {
    actingInAdmin($this->designer, $this->s->org);

    expect(MyTasks::canAccess())->toBeFalse();
});

it('approves, requests changes and runs manual workflows from the inbox detail', function (): void {
    W::workflow($this->s->org, [W::reviewStep($this->group)]);
    $manual = W::workflow($this->s->org, [W::step('notify', ['user_ids' => [$this->s->owner->id], 'message' => 'Bitte anrufen'])], TriggerType::Manual);
    $submission = W::submitted($this->s);

    actingInAdmin($this->reviewer, $this->s->org);
    Livewire::test(ViewSubmission::class, ['record' => $submission->uuid])->assertOk()
        ->assertActionVisible('approve')->assertActionVisible('reject')
        ->callAction('reject', ['comment' => 'Unterschrift fehlt'])->assertHasNoActionErrors();
    expect(W::fresh($this->s, $submission)->status->value)->toBe('changes_requested');

    actingInAdmin($this->s->owner, $this->s->org);
    Livewire::test(ViewSubmission::class, ['record' => $submission->uuid])->assertOk()
        ->assertActionHidden('approve')
        ->callAction('runWorkflow', ['workflow_id' => $manual->id])->assertHasNoActionErrors();
    $run = W::runs($this->s->org)->firstWhere('workflow_id', $manual->id);
    expect($run?->trigger)->toBe('manual')->and($run?->status)->toBe(RunStatus::Completed)
        ->and(Activity::query()->where('event', 'workflow.manual_run')->count())->toBe(1);
});

it('lets an owner decide an unassigned review from the inbox', function (): void {
    W::workflow($this->s->org, [W::reviewStep($this->group)]);
    $submission = W::submitted($this->s);
    actingInAdmin($this->s->owner, $this->s->org);

    Livewire::test(ViewSubmission::class, ['record' => $submission->uuid])->callAction('approve', ['comment' => null])->assertHasNoActionErrors();

    expect(W::fresh($this->s, $submission)->status->value)->toBe('completed');
});

it('manages webhook endpoints with a generated, encrypted, never-audited secret', function (): void {
    actingInAdmin($this->designer, $this->s->org);

    Livewire::test(ManageWebhookEndpoints::class)->assertOk()
        ->callAction('create', ['name' => 'Privat', 'url' => 'http://127.0.0.1/hook', 'secret' => '', 'active' => true])
        ->assertHasActionErrors(['url']);
    Livewire::test(ManageWebhookEndpoints::class)
        ->callAction('create', ['name' => 'CRM', 'url' => 'https://crm.example.com/hook', 'secret' => '', 'active' => true])
        ->assertHasNoActionErrors();

    $endpoint = WebhookEndpoint::query()->sole();
    $raw = DB::table('webhook_endpoints')->value('secret');
    expect(strlen($endpoint->secret))->toBe(40)
        ->and($raw)->not->toContain($endpoint->secret)
        ->and(json_encode(Activity::query()->where('subject_type', $endpoint->getMorphClass())->get()->toArray()))->not->toContain($endpoint->secret);

    $secret = $endpoint->secret;
    Livewire::test(ManageWebhookEndpoints::class)
        ->callTableAction('edit', $endpoint, ['name' => 'CRM', 'url' => 'https://crm.example.com/v2', 'secret' => '', 'active' => false])
        ->assertHasNoTableActionErrors();
    expect($endpoint->refresh()->secret)->toBe($secret)->and($endpoint->url)->toBe('https://crm.example.com/v2')->and($endpoint->active)->toBeFalse();
});

it('limits workflows and webhooks to workflows.manage', function (SystemRole $role, bool $allowed): void {
    actingInAdmin(memberOf($this->s->org, $role), $this->s->org);

    expect(WorkflowResource::canViewAny())->toBe($allowed)->and(WebhookEndpointResource::canViewAny())->toBe($allowed);
})->with([
    'owner' => [SystemRole::Owner, true],
    'admin' => [SystemRole::Admin, true],
    'designer' => [SystemRole::Designer, true],
    'reviewer' => [SystemRole::Reviewer, false],
    'field worker' => [SystemRole::FieldWorker, false],
    'viewer' => [SystemRole::Viewer, false],
]);
