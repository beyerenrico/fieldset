<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Workflows\Enums\TaskOutcome;
use App\Domain\Workflows\Tasks\CompleteReviewTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Workflows\WorkflowFixtures as W;

/* M4-T07: GET /tasks, POST /tasks/{id}/complete, submission review + open_task_id, manifest tasks (docs/api/field-app-v1.md). */

beforeEach(function (): void {
    W::notifier();
    $this->s = W::scenario();
    $this->reviewer = W::reviewer($this->s->org);
    $this->group = W::group($this->s->org, 'Prüfer', $this->reviewer);
    W::workflow($this->s->org, [W::reviewStep($this->group, 24)]);
    $this->submission = W::submitted($this->s);
    $this->task = W::task($this->s->org, $this->submission);
});

function completeTask(User $user, int|string $id, array $body, ?string $key = null): TestResponse
{
    return test()->withHeaders([...apiHeaders($user, test()->s->org), 'Idempotency-Key' => $key ?? (string) Str::uuid()])
        ->postJson("/api/v1/tasks/{$id}/complete", $body);
}

it('lists open tasks assigned to the user or their groups in the contract shape', function (): void {
    $this->withHeaders(apiHeaders($this->reviewer, $this->s->org))->getJson('/api/v1/tasks')->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0', [
            'id' => $this->task->id, 'type' => 'review', 'status' => 'open', 'outcome' => null, 'comment' => null,
            'due_at' => $this->task->due_at?->toIso8601String(), 'overdue' => false, 'created_at' => $this->task->created_at->toIso8601String(),
            'assignee' => ['type' => 'group', 'id' => $this->group->id, 'name' => 'Prüfer'],
            'submission' => [
                'uuid' => $this->submission->uuid, 'number' => $this->submission->number, 'status' => 'in_review',
                'form' => ['id' => $this->s->form->id, 'name' => $this->s->form->name],
                'submitted_by' => ['id' => $this->s->worker->id, 'name' => $this->s->worker->name],
                'submitted_at' => $this->submission->submitted_at?->toIso8601String(),
            ],
        ]);

    $this->withHeaders(apiHeaders($this->reviewer, $this->s->org))->getJson('/api/v1/tasks?status=completed')->assertOk()->assertJsonPath('meta.total', 0);
    $this->withHeaders(apiHeaders($this->s->owner, $this->s->org))->getJson('/api/v1/tasks')->assertOk()->assertJsonPath('meta.total', 0);
    $this->withHeaders(apiHeaders($this->reviewer, $this->s->org))->getJson('/api/v1/tasks?status=bogus')->assertStatus(422);
});

it('shows review and open_task_id on the submission and lists open tasks in the manifest', function (): void {
    $this->withHeaders(apiHeaders($this->reviewer, $this->s->org))->getJson('/api/v1/submissions/'.$this->submission->uuid)->assertOk()
        ->assertJsonPath('data.submission.open_task_id', $this->task->id)
        ->assertJsonPath('data.submission.review', null);
    $this->withHeaders(apiHeaders($this->s->owner, $this->s->org))->getJson('/api/v1/submissions/'.$this->submission->uuid)->assertOk()
        ->assertJsonPath('data.submission.open_task_id', null);
    $this->withHeaders(apiHeaders($this->reviewer, $this->s->org))->getJson('/api/v1/sync/manifest')->assertOk()
        ->assertJsonCount(1, 'data.tasks')->assertJsonPath('data.tasks.0.id', $this->task->id);
    $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))->getJson('/api/v1/sync/manifest')->assertOk()->assertJsonCount(0, 'data.tasks');
});

it('approves through the API and replays the same key', function (): void {
    $key = (string) Str::uuid();

    completeTask($this->reviewer, $this->task->id, ['outcome' => 'approve'], $key)->assertOk()
        ->assertJsonPath('data.task.status', 'completed')
        ->assertJsonPath('data.task.outcome', 'approved')
        ->assertJsonPath('data.submission.uuid', $this->submission->uuid)
        ->assertJsonPath('data.submission.open_task_id', null)
        ->assertJsonPath('data.submission.review.outcome', 'approved')
        ->assertJsonPath('data.submission.review.by', ['id' => $this->reviewer->id, 'name' => $this->reviewer->name]);
    completeTask($this->reviewer, $this->task->id, ['outcome' => 'approve'], $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    completeTask($this->reviewer, $this->task->id, ['outcome' => 'approve'])->assertStatus(409)->assertJsonPath('error.code', 'task_closed');
    expect(W::fresh($this->s, $this->submission)->status->value)->toBe('completed');
});

it('rejects with a comment; the worker sees the review on the submission', function (): void {
    completeTask($this->reviewer, $this->task->id, ['outcome' => 'reject'])->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.details.0.pointer', '/comment');
    completeTask($this->reviewer, $this->task->id, ['outcome' => 'maybe', 'comment' => 'x'])->assertStatus(422)
        ->assertJsonPath('error.details.0.pointer', '/outcome');
    completeTask($this->reviewer, $this->task->id, ['outcome' => 'reject', 'comment' => str_repeat('x', 2001)])->assertStatus(422);

    completeTask($this->reviewer, $this->task->id, ['outcome' => 'reject', 'comment' => 'Bitte Seriennummer ergänzen'])->assertOk()
        ->assertJsonPath('data.submission.status', 'changes_requested');

    $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))->getJson('/api/v1/submissions/'.$this->submission->uuid)->assertOk()
        ->assertJsonPath('data.submission.can_edit', true)
        ->assertJsonPath('data.submission.review.outcome', 'rejected')
        ->assertJsonPath('data.submission.review.comment', 'Bitte Seriennummer ergänzen');
});

it('answers 404 for tasks the user cannot act on', function (): void {
    completeTask($this->s->worker, $this->task->id, ['outcome' => 'approve'])->assertNotFound()->assertJsonPath('error.code', 'not_found');
    completeTask($this->s->owner, $this->task->id, ['outcome' => 'approve'])->assertNotFound();   // not assigned
    completeTask(W::reviewer($this->s->org), $this->task->id, ['outcome' => 'approve'])->assertNotFound();
    completeTask($this->reviewer, 'abc', ['outcome' => 'approve'])->assertNotFound();
    completeTask($this->reviewer, 999999, ['outcome' => 'approve'])->assertNotFound();

    expect(W::task($this->s->org, $this->submission)->isOpen())->toBeTrue();
});

it('lists completed tasks with their outcome', function (): void {
    app(TenantContext::class)->run($this->s->org, fn () => app(CompleteReviewTask::class)->handle($this->task, $this->reviewer, TaskOutcome::Approved, 'Passt'));

    $this->withHeaders(apiHeaders($this->reviewer, $this->s->org))->getJson('/api/v1/tasks?status=completed')->assertOk()
        ->assertJsonPath('data.0.outcome', 'approved')->assertJsonPath('data.0.comment', 'Passt');
});

it('lists submissions with review and open_task_id per row without per-row queries (M8-T05)', function (): void {
    $decided = W::submitted($this->s);
    app(TenantContext::class)->run($this->s->org, fn () => app(CompleteReviewTask::class)->handle(W::task($this->s->org, $decided), $this->reviewer, TaskOutcome::Approved, null));
    $list = function (): array {
        app('auth')->forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $this->withHeaders(apiHeaders($this->reviewer, $this->s->org))->getJson('/api/v1/submissions')->assertOk()->json('data');

        // Queries a per-row presenter would repeat: tasks, group memberships, attachments, versions.
        $perRow = array_filter(DB::getQueryLog(), fn (array $q): bool => preg_match('/^select .* from "(tasks|group_user|attachments|form_versions)"/', $q['query']) === 1);

        return [collect($rows)->keyBy('uuid'), count($perRow)];
    };

    [$rows, $queries] = $list();
    expect($rows[$this->submission->uuid]['open_task_id'])->toBe($this->task->id)
        ->and($rows[$this->submission->uuid]['review'])->toBeNull()
        ->and($rows[$decided->uuid]['open_task_id'])->toBeNull()
        ->and($rows[$decided->uuid]['review']['outcome'])->toBe('approved')
        ->and($rows[$decided->uuid]['review']['by']['id'])->toBe($this->reviewer->id);

    foreach (range(1, 4) as $i) {
        W::submitted($this->s);
    }
    [$more, $queriesWithMore] = $list();
    expect($more)->toHaveCount(6)->and($queriesWithMore)->toBe($queries);
});
