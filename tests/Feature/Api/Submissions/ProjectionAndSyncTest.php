<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\SyncFormAccess;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Actions\SyncMemberGroups;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Actions\TransitionSubmission;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

beforeEach(function (): void {
    Storage::fake('local');
    $this->s = FieldAppScenario::make();
});

function transitionTo(string $uuid, SubmissionStatus ...$targets): void
{
    $s = test()->s;
    app(TenantContext::class)->run($s->org, function () use ($uuid, $targets, $s): void {
        foreach ($targets as $target) {
            app(TransitionSubmission::class)->handle(Submission::query()->findOrFail($uuid), $target, $s->owner);
        }
    });
}

function manifest(?string $since = null): TestResponse
{
    $s = test()->s;
    $query = $since === null ? '' : '?since='.urlencode($since);

    return test()->withHeaders(apiHeaders($s->worker, $s->org))->getJson('/api/v1/sync/manifest'.$query)->assertOk();
}

/** A second published form (invoice template) that nobody is granted yet. */
function ungrantedForm(): Form
{
    $s = test()->s;

    return app(TenantContext::class)->run($s->org, function () use ($s): Form {
        $form = app(CreateFormFromTemplate::class)->handle($s->owner, 'invoice');
        app(PublishForm::class)->handle($form, 'v1', $s->owner);

        return $form->refresh();
    });
}

it('keeps submission_values.status in sync with status transitions', function (): void {
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);
    FieldAppApi::submit($this->s, $this->s->worker, $uuid, 1)->assertOk();

    transitionTo($uuid, SubmissionStatus::InReview);

    expect(DB::table('submission_values')->where('submission_uuid', $uuid)->distinct()->pluck('status')->all())->toBe(['in_review']);
});

it('does not project unsubmitted edits of a submission sent back for changes', function (): void {
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);
    FieldAppApi::submit($this->s, $this->s->worker, $uuid, 1)->assertOk();
    transitionTo($uuid, SubmissionStatus::InReview, SubmissionStatus::ChangesRequested);
    $data = DB::table('submissions')->where('uuid', $uuid)->value('data');
    $edited = [...json_decode((string) $data, true), 'customer_name' => 'Edited GmbH'];
    FieldAppApi::put($this->s, $this->s->worker, $uuid, $edited, 2)->assertOk();

    $this->artisan('app:reproject')->assertSuccessful();

    $values = DB::table('submission_values')->where('submission_uuid', $uuid);
    expect((clone $values)->where('key', 'customer_name')->value('value_text'))->toBe('Beispiel GmbH')
        ->and((clone $values)->distinct()->pluck('status')->all())->toBe(['changes_requested']);
});

it('lists a form granted after the cursor in the incremental manifest', function (): void {
    $second = ungrantedForm();
    $this->travel(5)->seconds();
    $first = manifest();
    expect($first->json('data.fillable_form_ids'))->toBe([$this->s->form->id]);

    $this->travel(5)->seconds();
    app(TenantContext::class)->run($this->s->org, fn () => app(SyncFormAccess::class)->handle($second, [], ['field_worker'], $this->s->owner));

    $next = manifest($first->json('data.cursor'));
    expect($next->json('data.forms.*.id'))->toBe([$second->id])
        ->and($next->json('data.fillable_form_ids'))->toEqualCanonicalizing([$this->s->form->id, $second->id]);
});

it('lists forms granted through a new group membership in the incremental manifest', function (): void {
    $second = ungrantedForm();
    $crew = app(TenantContext::class)->run($this->s->org, function () use ($second): Group {
        $crew = Group::query()->create(['name' => 'Crew']);
        app(SyncFormAccess::class)->handle($second, [$crew->id], [], $this->s->owner);

        return $crew;
    });
    $this->travel(5)->seconds();
    $first = manifest();

    $this->travel(5)->seconds();
    app(SyncMemberGroups::class)->handle($this->s->org, $this->s->worker, [$crew->id], $this->s->owner);

    expect(manifest($first->json('data.cursor'))->json('data.forms.*.id'))->toBe([$second->id]);
});

it('includes forms changed in the same second as the cursor', function (): void {
    $this->travel(5)->seconds();
    $this->freezeSecond();
    $first = manifest();

    app(TenantContext::class)->run($this->s->org, fn () => $this->s->form->touch());

    expect(manifest($first->json('data.cursor'))->json('data.forms.*.id'))->toBe([$this->s->form->id]);
});
