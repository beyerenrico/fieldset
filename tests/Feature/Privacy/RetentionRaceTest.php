<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Privacy\Enums\RetentionAction;
use App\Domain\Privacy\Retention\ApplyOrganizationRetention;
use App\Domain\Privacy\Retention\ApplyRetention;
use App\Domain\Privacy\Retention\DeleteSubmissionRecord;
use App\Domain\Privacy\Retention\SaveRetentionPolicy;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

/* ADR 0039: retention decides under the row lock, scrubs notifications and runs in time-boxed batches. */

beforeEach(function (): void {
    Storage::fake('local');
    $this->s = FieldAppScenario::make();
    $this->tenant = app(TenantContext::class);
});

/** A service report created (and, with $submit, submitted) the given number of months ago; returns its uuid. */
function retentionCandidate(object $test, int $monthsAgo = 30, bool $submit = true): string
{
    $test->travel(-$monthsAgo)->months();
    $uuid = FieldAppApi::readyDraft($test->s, $test->s->worker);
    if ($submit) {
        FieldAppApi::submit($test->s, $test->s->worker, $uuid, 1)->assertOk();
    }
    $test->travelBack();

    return $uuid;
}

function retentionPolicy(object $test, int $months, RetentionAction $action): void
{
    $test->tenant->run($test->s->org, fn () => app(SaveRetentionPolicy::class)->handle($test->s->form, $months, $action, $test->s->owner));
}

/** Runs $change once, right after the batch read the due submissions and before any row is locked. */
function betweenSelectAndLock(Closure $change): void
{
    $fired = false;
    Submission::retrieved(function () use (&$fired, $change): void {
        if (! $fired) {
            $fired = true;
            $change();
        }
    });
}

function untouched(object $test, string $uuid): void
{
    $test->tenant->run($test->s->org, function () use ($uuid): void {
        expect(Submission::query()->whereKey($uuid)->whereNull('anonymized_at')->exists())->toBeTrue()
            ->and(Activity::query()->whereIn('event', ['submission.deleted', 'submission.anonymized'])->exists())->toBeFalse();
    });
}

function storeNotification(object $test, ?string $submissionUuid, string $body): string
{
    $id = (string) Str::uuid7();
    DB::table('notifications')->insert([
        'id' => $id, 'type' => 'app.notification', 'notifiable_type' => $test->s->owner->getMorphClass(), 'notifiable_id' => $test->s->owner->id,
        'data' => json_encode(['title' => 'Changes requested', 'body' => $body, 'text' => ['body' => $body], 'submission_uuid' => $submissionUuid]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('does not delete a due draft that was submitted while the batch ran (anonymize policy)', function (): void {
    $draft = retentionCandidate($this, 30, submit: false);
    retentionPolicy($this, 24, RetentionAction::Anonymize);
    betweenSelectAndLock(fn () => DB::table('submissions')->where('uuid', $draft)->update(['status' => 'submitted', 'submitted_at' => now()]));

    $this->tenant->run($this->s->org, fn () => app(ApplyRetention::class)->handle());

    untouched($this, $draft);
});

it('re-reads due-ness and the policy under the row lock', function (Closure $change): void {
    $uuid = retentionCandidate($this);
    retentionPolicy($this, 24, RetentionAction::Delete);
    betweenSelectAndLock(fn () => $change($uuid));

    $this->tenant->run($this->s->org, fn () => app(ApplyRetention::class)->handle());

    untouched($this, $uuid);
})->with([
    'resubmitted meanwhile' => [fn (string $uuid) => DB::table('submissions')->where('uuid', $uuid)->update(['submitted_at' => now()])],
    'retention raised meanwhile' => [fn () => DB::table('retention_policies')->update(['months' => 60])],
    'set to keep meanwhile' => [fn () => DB::table('retention_policies')->update(['months' => null])],
]);

it('follows an action changed from anonymize to delete while the batch ran', function (): void {
    $uuid = retentionCandidate($this);
    retentionPolicy($this, 24, RetentionAction::Anonymize);
    betweenSelectAndLock(fn () => DB::table('retention_policies')->update(['action' => 'delete']));

    $this->tenant->run($this->s->org, fn () => app(ApplyRetention::class)->handle());

    $this->tenant->run($this->s->org, fn () => expect(Submission::query()->whereKey($uuid)->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'submission.deleted')->count())->toBe(1));
});

it('deletes the in-app notifications of an anonymized or deleted submission, comments included', function (): void {
    $anonymized = retentionCandidate($this);
    $deleted = retentionCandidate($this);
    $kept = retentionCandidate($this, 2);
    $about = [
        storeNotification($this, $anonymized, 'Frau Weber: please fix the address'),
        storeNotification($this, $deleted, 'Frau Weber: signature missing'),
    ];
    $others = [storeNotification($this, $kept, 'Recent one'), storeNotification($this, null, 'Export ready')];
    retentionPolicy($this, 24, RetentionAction::Anonymize);

    $this->tenant->run($this->s->org, function () use ($deleted): void {
        app(DeleteSubmissionRecord::class)->handle(Submission::query()->findOrFail($deleted), 'retention');
        app(ApplyRetention::class)->handle();
    });

    expect(DB::table('notifications')->whereIn('id', $about)->exists())->toBeFalse()
        ->and(DB::table('notifications')->whereIn('id', $others)->count())->toBe(2)
        ->and(DB::table('notifications')->pluck('data')->implode(' '))->not->toContain('Weber');
});

it('time-boxes a run below the queue retry_after and queues the rest', function (): void {
    $first = retentionCandidate($this);
    $second = retentionCandidate($this);
    retentionPolicy($this, 24, RetentionAction::Anonymize);
    $travelled = false;
    Submission::updated(function () use (&$travelled): void {
        if (! $travelled) {
            $travelled = true;
            $this->travel(ApplyOrganizationRetention::TIME_BOX_SECONDS + 1)->seconds();
        }
    });
    Queue::fake();

    app()->call([new ApplyOrganizationRetention($this->s->org->id), 'handle']);

    $anonymized = fn (): int => $this->tenant->run($this->s->org, fn (): int => Submission::query()->whereIn('uuid', [$first, $second])->whereNotNull('anonymized_at')->count());
    expect($anonymized())->toBe(1);
    Queue::assertPushed(ApplyOrganizationRetention::class, 1);

    app()->call([new ApplyOrganizationRetention($this->s->org->id), 'handle']);
    expect($anonymized())->toBe(2);
    Queue::assertPushed(ApplyOrganizationRetention::class, 1);

    $timeout = new ReflectionClass(ApplyOrganizationRetention::class)->getAttributes(Timeout::class)[0]->newInstance()->timeout;
    expect($timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        ->and(ApplyOrganizationRetention::TIME_BOX_SECONDS)->toBeLessThan($timeout);
});
