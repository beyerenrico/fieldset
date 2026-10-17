<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Privacy\Enums\RetentionAction;
use App\Domain\Privacy\Holds\PlaceLegalHold;
use App\Domain\Privacy\Holds\ReleaseLegalHold;
use App\Domain\Privacy\Models\RetentionPolicy;
use App\Domain\Privacy\Retention\AnonymizeSubmission;
use App\Domain\Privacy\Retention\ApplyOrganizationRetention;
use App\Domain\Privacy\Retention\ApplyRetention;
use App\Domain\Privacy\Retention\DeleteSubmissionRecord;
use App\Domain\Privacy\Retention\SaveRetentionPolicy;
use App\Domain\Privacy\Retention\SubmissionTraces;
use App\Domain\Submissions\Actions\TransitionSubmission;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Attachment;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Models\SubmissionEvent;
use App\Domain\Workflows\Models\Task;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

/* §11 Privacy, ADR 0039: per-form retention (delete or anonymize after N months), legal holds, idempotent job. */

beforeEach(function (): void {
    Storage::fake('local');
    $this->s = FieldAppScenario::make();
    $this->tenant = app(TenantContext::class);
});

/** A submitted service report with a signature and a stored PDF, submitted the given number of months ago. */
function oldSubmission(object $test, int $monthsAgo = 30): Submission
{
    $test->travel(-$monthsAgo)->months();
    $uuid = FieldAppApi::readyDraft($test->s, $test->s->worker);
    FieldAppApi::submit($test->s, $test->s->worker, $uuid, 1)->assertOk();
    $test->travelBack();

    return $test->tenant->run($test->s->org, function () use ($uuid): Submission {
        Storage::disk('local')->put("renditions/{$uuid}.pdf", '%PDF-1.7 personal data');
        Rendition::factory()->create(['submission_uuid' => $uuid, 'path' => "renditions/{$uuid}.pdf", 'input_hash' => str_repeat('c', 64), 'file_sha256' => str_repeat('d', 64)]);

        return Submission::query()->findOrFail($uuid);
    });
}

function retain(object $test, int $months, RetentionAction $action): RetentionPolicy
{
    return $test->tenant->run($test->s->org, fn (): RetentionPolicy => app(SaveRetentionPolicy::class)->handle($test->s->form, $months, $action, $test->s->owner));
}

function runRetention(object $test): void
{
    $test->tenant->run($test->s->org, fn () => app(ApplyRetention::class)->handle());
}

it('anonymizes due submissions: personal values, attachments and PDF files go, numbers and rendition hashes stay', function (): void {
    $submission = oldSubmission($this);
    $attachment = $this->tenant->run($this->s->org, fn () => Attachment::query()->where('submission_uuid', $submission->uuid)->sole());
    retain($this, 24, RetentionAction::Anonymize);

    runRetention($this);

    $this->tenant->run($this->s->org, function () use ($submission, $attachment): void {
        $fresh = Submission::query()->findOrFail($submission->uuid);
        $data = json_decode((string) json_encode($fresh->data), true);
        expect($data)->not->toHaveKeys(['customer_name', 'customer_email', 'site_address', 'technician', 'defect', 'work_done', 'customer_signature', 'photos'])
            ->and($data['service_type'])->toBe('repair')->and($data['work_accepted'])->toBeTrue()
            ->and($data['items'][0])->toEqual(['qty' => '1.5', 'unit' => 'h', 'unit_price' => '65.00', 'line_total' => '97.50'])
            ->and($fresh->submitted_by)->toBeNull()->and($fresh->created_by)->toBeNull()->and($fresh->revision)->toBe(3)
            ->and(Attachment::query()->where('submission_uuid', $submission->uuid)->exists())->toBeFalse()
            ->and(Storage::disk('local')->exists($attachment->path))->toBeFalse()
            ->and(Storage::disk('local')->exists("renditions/{$submission->uuid}.pdf"))->toBeFalse()
            ->and(Rendition::query()->where('submission_uuid', $submission->uuid)->sole()->file_sha256)->toBe(str_repeat('d', 64))
            ->and(SubmissionEvent::query()->where('submission_uuid', $submission->uuid)->whereNotNull('actor_id')->exists())->toBeFalse();

        $values = DB::table('submission_values')->where('submission_uuid', $submission->uuid)->pluck('value_text', 'key')->all();
        expect($values)->toHaveKey('items.qty')->toHaveKey('gross_total')->not->toHaveKey('customer_name')->not->toHaveKey('items.description')
            ->and(implode(' ', $values))->not->toContain('Beispiel GmbH');

        $entry = Activity::query()->where('event', 'submission.anonymized')->sole();
        expect($entry->properties['renditions'][0]['file_sha256'])->toBe(str_repeat('d', 64))
            ->and($entry->properties['reason'])->toBe('retention')->and($entry->causer_id)->toBeNull()
            ->and(Activity::query()->where('event', 'privacy.retention_applied')->sole()->properties['anonymized'])->toBe(1);
    });
});

it('is idempotent and leaves submissions inside the retention period alone', function (): void {
    $old = oldSubmission($this, 30);
    $recent = oldSubmission($this, 6);
    retain($this, 24, RetentionAction::Anonymize);

    runRetention($this);
    runRetention($this);

    $this->tenant->run($this->s->org, function () use ($old, $recent): void {
        expect(Activity::query()->where('event', 'submission.anonymized')->count())->toBe(1)
            ->and(Activity::query()->where('event', 'privacy.retention_applied')->count())->toBe(1)
            ->and(Submission::query()->whereKey($old->uuid)->whereNotNull('anonymized_at')->exists())->toBeTrue()
            ->and(Submission::query()->whereKey($recent->uuid)->whereNull('anonymized_at')->exists())->toBeTrue()
            ->and(Attachment::query()->where('submission_uuid', $recent->uuid)->exists())->toBeTrue();
    });
});

it('deletes due submissions with everything attached, including immutable renditions', function (): void {
    $submission = oldSubmission($this);
    retain($this, 24, RetentionAction::Delete);

    runRetention($this);

    $this->tenant->run($this->s->org, function () use ($submission): void {
        expect(Submission::query()->whereKey($submission->uuid)->exists())->toBeFalse()
            ->and(Rendition::query()->where('submission_uuid', $submission->uuid)->exists())->toBeFalse()
            ->and(DB::table('submission_values')->where('submission_uuid', $submission->uuid)->exists())->toBeFalse()
            ->and(Storage::disk('local')->exists("renditions/{$submission->uuid}.pdf"))->toBeFalse();
        $entry = Activity::query()->where('event', 'submission.deleted')->sole();
        expect($entry->subject_id)->toBe($submission->uuid)->and($entry->properties['number'])->toBe($submission->number)
            ->and($entry->properties['renditions'][0]['input_hash'])->toBe(str_repeat('c', 64));
    });
    // The renditions trigger flag is switched off again: a later delete outside retention is refused.
    expect(DB::scalar("SELECT current_setting('app.allow_immutable_delete', true)"))->toBe('off');
});

it('deletes old drafts even when the policy anonymizes', function (): void {
    $this->travel(-30)->months();
    $draft = FieldAppApi::readyDraft($this->s, $this->s->worker);
    $this->travelBack();
    retain($this, 24, RetentionAction::Anonymize);

    runRetention($this);

    expect($this->tenant->run($this->s->org, fn () => Submission::query()->whereKey($draft)->exists()))->toBeFalse();
});

it('skips submissions under a legal hold of their form or their own until the hold is released', function (): void {
    $held = oldSubmission($this);
    $other = oldSubmission($this);
    retain($this, 120, RetentionAction::Delete);
    $this->travel(11)->years();

    $formHold = $this->tenant->run($this->s->org, fn () => app(PlaceLegalHold::class)->forForm($this->s->form, 'Tax audit 2026', $this->s->owner));
    runRetention($this);
    expect($this->tenant->run($this->s->org, fn () => Submission::query()->count()))->toBe(2);

    $this->tenant->run($this->s->org, function () use ($formHold, $held): void {
        app(PlaceLegalHold::class)->forSubmission($held, 'Customer dispute', $this->s->owner);
        app(ReleaseLegalHold::class)->handle($formHold, $this->s->owner);
    });
    runRetention($this);

    $this->tenant->run($this->s->org, function () use ($held, $other): void {
        expect(Submission::query()->whereKey($held->uuid)->exists())->toBeTrue()
            ->and(Submission::query()->whereKey($other->uuid)->exists())->toBeFalse()
            ->and(Activity::query()->whereIn('event', ['privacy.legal_hold_placed', 'privacy.legal_hold_released'])->orderBy('id')->pluck('event')->all())
            ->toBe(['privacy.legal_hold_placed', 'privacy.legal_hold_placed', 'privacy.legal_hold_released']);
    });
});

it('keeps forms under legal hold at the configurable minimum retention', function (): void {
    config(['privacy.retention.legal_hold_min_months' => 96]);
    $this->tenant->run($this->s->org, function (): void {
        app(SaveRetentionPolicy::class)->handle($this->s->form, 12, RetentionAction::Delete, $this->s->owner);

        expect(fn () => app(PlaceLegalHold::class)->forForm($this->s->form, 'Invoices', $this->s->owner))->toThrow(ValidationException::class);

        app(SaveRetentionPolicy::class)->handle($this->s->form, 96, RetentionAction::Delete, $this->s->owner);
        app(PlaceLegalHold::class)->forForm($this->s->form, 'Invoices', $this->s->owner);

        expect(fn () => app(SaveRetentionPolicy::class)->handle($this->s->form, 95, RetentionAction::Delete, $this->s->owner))->toThrow(ValidationException::class)
            ->and(app(SaveRetentionPolicy::class)->handle($this->s->form, null, RetentionAction::Delete, $this->s->owner)->months)->toBeNull();
    });
});

it('audits each effective retention change once and validates the period', function (): void {
    $this->tenant->run($this->s->org, function (): void {
        app(SaveRetentionPolicy::class)->handle($this->s->form, 24, RetentionAction::Anonymize, $this->s->owner);
        app(SaveRetentionPolicy::class)->handle($this->s->form, 24, RetentionAction::Anonymize, $this->s->owner);
        app(SaveRetentionPolicy::class)->handle($this->s->form, 36, RetentionAction::Delete, $this->s->owner);

        expect(fn () => app(SaveRetentionPolicy::class)->handle($this->s->form, 0, RetentionAction::Delete, $this->s->owner))->toThrow(ValidationException::class)
            ->and(fn () => app(SaveRetentionPolicy::class)->handle($this->s->form, 1201, RetentionAction::Delete, $this->s->owner))->toThrow(ValidationException::class);

        $entries = Activity::query()->where('event', 'privacy.retention_changed')->orderBy('id')->get();
        expect($entries)->toHaveCount(2)
            ->and($entries[1]->properties['before'])->toBe(['months' => 24, 'action' => 'anonymize'])
            ->and($entries[1]->properties['after'])->toBe(['months' => 36, 'action' => 'delete']);
    });
});

it('lets only organization managers set retention and holds', function (): void {
    $designer = memberOf($this->s->org, SystemRole::Designer);
    $this->tenant->run($this->s->org, function () use ($designer): void {
        expect(fn () => app(SaveRetentionPolicy::class)->handle($this->s->form, 24, RetentionAction::Delete, $designer))->toThrow(AuthorizationException::class)
            ->and(fn () => app(PlaceLegalHold::class)->forForm($this->s->form, 'Reason', $designer))->toThrow(AuthorizationException::class);
    });
});

it('queues one tenant-aware job per organization with a policy and never crosses organizations', function (): void {
    $submission = oldSubmission($this);
    $other = FieldAppScenario::make();
    $this->travel(-30)->months();
    $foreign = FieldAppApi::readyDraft($other, $other->worker);
    FieldAppApi::submit($other, $other->worker, $foreign, 1)->assertOk();
    $this->travelBack();
    retain($this, 24, RetentionAction::Delete);

    Queue::fake();
    $this->artisan('privacy:apply-retention')->assertSuccessful();
    Queue::assertPushed(ApplyOrganizationRetention::class, 1);
    Queue::assertPushed(ApplyOrganizationRetention::class, fn (ApplyOrganizationRetention $job): bool => $job->organizationId === $this->s->org->id);

    app()->call([new ApplyOrganizationRetention($this->s->org->id), 'handle']);
    expect($this->tenant->run($this->s->org, fn () => Submission::query()->whereKey($submission->uuid)->exists()))->toBeFalse()
        ->and($this->tenant->run($other->org, fn () => Submission::query()->whereKey($foreign)->exists()))->toBeTrue();
});

it('removes the traces of an anonymized submission in mails, sends, tasks and workflow runs', function (): void {
    $submission = oldSubmission($this);
    $this->tenant->run($this->s->org, function () use ($submission): void {
        MailMessage::factory()->create(['submission_uuid' => $submission->uuid, 'recipient_email' => 'kunde@example.com']);
        DocumentSend::factory()->create(['submission_uuid' => $submission->uuid, 'recipient_email' => 'kunde@example.com', 'recipient_name' => 'Frau Weber']);
        Task::factory()->create(['submission_uuid' => $submission->uuid, 'comment' => 'Frau Weber called', 'assignee_user_id' => $this->s->owner->id]);
    });
    retain($this, 24, RetentionAction::Anonymize);

    runRetention($this);

    expect(DB::table('mail_messages')->where('submission_uuid', $submission->uuid)->exists())->toBeFalse()
        ->and(DB::table('document_sends')->where('submission_uuid', $submission->uuid)->value('recipient_email'))->toBe(SubmissionTraces::MASKED_EMAIL)
        ->and(DB::table('tasks')->where('submission_uuid', $submission->uuid)->value('comment'))->toBeNull()
        ->and(json_encode(DB::table('document_sends')->get()).json_encode(DB::table('tasks')->get()))->not->toContain('Weber')->not->toContain('kunde@example.com');
});

it('re-checks legal holds under the row lock, so a hold placed during a run wins', function (): void {
    $submission = oldSubmission($this);

    $this->tenant->run($this->s->org, function () use ($submission): void {
        app(PlaceLegalHold::class)->forSubmission($submission, 'Placed while the batch ran', $this->s->owner);

        expect(app(AnonymizeSubmission::class)->handle($submission, 'retention'))->toBeFalse()
            ->and(app(DeleteSubmissionRecord::class)->handle($submission, 'retention'))->toBeFalse()
            ->and(Submission::query()->whereKey($submission->uuid)->whereNull('anonymized_at')->exists())->toBeTrue();
    });
});

it('keeps the last submitted values of a submission waiting for changes, minus the removed keys', function (): void {
    $submission = oldSubmission($this);
    $this->tenant->run($this->s->org, function () use ($submission): void {
        app(TransitionSubmission::class)->handle($submission->refresh(), SubmissionStatus::InReview, $this->s->owner);
        app(TransitionSubmission::class)->handle($submission->refresh(), SubmissionStatus::ChangesRequested, $this->s->owner, 'Please fix');
        $draft = $submission->refresh();
        $data = $draft->data;
        $data->service_type = 'maintenance'; // unsubmitted edit
        $draft->forceFill(['data' => $data])->save();
    });
    retain($this, 24, RetentionAction::Anonymize);

    runRetention($this);

    $values = DB::table('submission_values')->where('submission_uuid', $submission->uuid)->pluck('value_text', 'key')->all();
    expect($values['service_type'] ?? null)->toBe('repair')->and($values)->not->toHaveKey('customer_name')->toHaveKey('items.qty');
});
