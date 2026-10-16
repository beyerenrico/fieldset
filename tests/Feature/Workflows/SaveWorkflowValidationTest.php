<?php

declare(strict_types=1);

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Workflows\Actions\SaveWorkflow;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Models\Workflow;
use Tests\Feature\Workflows\WorkflowFixtures as W;

/* M4 review fixes C1, C4, S3, U2: what SaveWorkflow refuses. */

beforeEach(function (): void {
    W::notifier();
    $this->s = W::scenario();
    $this->group = W::group($this->s->org, 'Prüfer', W::reviewer($this->s->org));
});

/**
 * @param  list<array{type: string, data: array<string, mixed>}>  $steps
 * @param  array<string, mixed>  $extra
 */
function saveWorkflow(array $steps, array $extra = [], ?Workflow $workflow = null): Workflow
{
    return app(TenantContext::class)->run(test()->s->org, fn (): Workflow => app(SaveWorkflow::class)->handle($workflow, [
        'name' => 'W', 'trigger_type' => TriggerType::SubmissionSubmitted->value, 'steps' => $steps, ...$extra,
    ], test()->s->owner));
}

function notifyStep(array $data = []): array
{
    return W::step('notify', ['user_ids' => [test()->s->owner->id], 'channels' => ['database'], 'message' => 'x', ...$data]);
}

it('lets set_status target only completed and void', function (string $status, bool $allowed): void {
    $save = fn () => saveWorkflow([W::step('set_status', ['status' => $status])]);

    $allowed ? expect($save()->steps[0]['data']['status'])->toBe($status) : expect($save)->toThrow(InvalidArgumentException::class);
})->with([
    'completed' => ['completed', true],
    'void' => ['void', true],
    'approved' => ['approved', false],
    'in_review' => ['in_review', false],
    'changes_requested' => ['changes_requested', false],
    'submitted' => ['submitted', false],
    'draft' => ['draft', false],
]);

it('fails a stored set_status step with another target instead of stranding the submission', function (): void {
    W::workflow($this->s->org, [W::step('set_status', ['status' => 'approved'])]);   // legacy definition

    $submission = W::submitted($this->s);

    $run = W::runs($this->s->org)->sole();
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error)->toBe(__('workflows.errors.status_not_allowed', ['status' => SubmissionStatus::Approved->label()]))
        ->and(W::fresh($this->s, $submission)->status)->toBe(SubmissionStatus::Submitted);
});

it('requires channels and a recipient for notify, and a recipient source for send_email', function (array $step): void {
    expect(fn () => saveWorkflow([$step]))->toThrow(InvalidArgumentException::class);
})->with([
    'notify without channels' => [['type' => 'notify', 'data' => ['user_ids' => [1], 'channels' => [], 'message' => 'x']]],
    'notify without recipients' => [['type' => 'notify', 'data' => ['channels' => ['mail'], 'message' => 'x']]],
    'send_email without recipients' => [['type' => 'send_email', 'data' => ['subject' => 'S', 'to_submitter' => false]]],
]);

it('accepts any single recipient source', function (array $data): void {
    expect(saveWorkflow([W::step('send_email', ['subject' => 'S', ...$data])])->steps)->toHaveCount(1);
})->with([
    'fixed' => [['to_addresses' => ['office@example.com']]],
    'field' => [['to_fields' => ['customer.email']]],
    'submitter' => [['to_submitter' => true]],
    'groups' => [['to_group_ids' => [1]]],
]);

it('caps steps, recipient lists and the definition size', function (callable $steps, callable $message): void {
    expect(fn () => saveWorkflow($steps()))->toThrow(InvalidArgumentException::class, $message());
})->with([
    'too many steps' => [fn () => array_fill(0, 26, notifyStep()), fn () => __('workflows.validation.too_many_steps', ['max' => 25])],
    'too many fixed addresses' => [
        fn () => [W::step('send_email', ['subject' => 'S', 'to_addresses' => array_map(fn (int $i): string => "a{$i}@example.com", range(1, 51))])],
        fn () => __('workflows.validation.too_many_recipients', ['max' => 50]),
    ],
    'too many address fields' => [
        fn () => [W::step('send_email', ['subject' => 'S', 'to_fields' => array_map(fn (int $i): string => "field_{$i}", range(1, 11))])],
        fn () => __('workflows.validation.too_many_fields', ['max' => 10]),
    ],
    'definition too large' => [fn () => [notifyStep(['message' => str_repeat('x', 70_000)])], fn () => __('workflows.validation.too_large', ['max' => 64])],
]);

it('refuses a second active review workflow on the same trigger and forms', function (): void {
    $review = [W::reviewStep($this->group)];
    $first = saveWorkflow($review);

    expect(fn () => saveWorkflow($review, ['trigger_config' => ['form_ids' => [$this->s->form->id]]]))
        ->toThrow(InvalidArgumentException::class, __('workflows.validation.review_conflict', ['name' => $first->name]));
    expect(fn () => saveWorkflow($review, ['trigger_type' => TriggerType::SubmissionStatusChanged->value, 'trigger_config' => ['to_status' => 'submitted']]))
        ->toThrow(InvalidArgumentException::class);

    // Not in conflict: inactive, another target status, a manual workflow, or the workflow itself.
    expect(saveWorkflow($review, ['active' => false])->active)->toBeFalse()
        ->and(saveWorkflow($review, ['trigger_type' => TriggerType::SubmissionStatusChanged->value, 'trigger_config' => ['to_status' => 'completed']])->id)->toBeInt()
        ->and(saveWorkflow($review, ['trigger_type' => TriggerType::Manual->value])->id)->toBeInt()
        ->and(saveWorkflow($review, ['name' => 'Umbenannt'], $first)->name)->toBe('Umbenannt');
});

it('allows review workflows for disjoint forms', function (): void {
    $review = [W::reviewStep($this->group)];
    saveWorkflow($review, ['trigger_config' => ['form_ids' => [$this->s->form->id]]]);

    expect(saveWorkflow($review, ['trigger_config' => ['form_ids' => [$this->s->form->id + 1000]]])->id)->toBeInt();
});
