<?php

declare(strict_types=1);

use App\Domain\Documents\Events\RenditionCreated;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Renditions\RenderSubmission;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Enums\DocumentSendFailure;
use App\Domain\Mail\Enums\DocumentSendStatus;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Jobs\DeliverMail;
use App\Domain\Mail\Listeners\SendWaitingDocuments;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Submissions\Actions\TransitionSubmission;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Feature\Mail\MailFixtures;
use Tests\Support\FieldAppScenario;

/* M3 review #5: re-checks at delivery; nothing goes out for voided submissions or with a stale PDF. */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    DocumentFixtures::fakeGotenberg();
    config(['documents.render_on_submit' => false]);
    $this->s = FieldAppScenario::make();
    $this->tenant = app(TenantContext::class);
});

function safetySend(Submission $submission, array $body = ['email' => 'kunde@example.com']): TestResponse
{
    $s = test()->s;

    return test()->withHeaders([...apiHeaders($s->worker, $s->org), 'Idempotency-Key' => (string) Str::uuid()])
        ->postJson("/api/v1/submissions/{$submission->uuid}/send", $body);
}

function safetySubmitted(): Submission
{
    $s = test()->s;
    $submission = DocumentFixtures::submit($s, DocumentFixtures::draft($s));

    return app(TenantContext::class)->run($s->org, fn (): Submission => $submission->refresh());
}

function safetyRow(Submission $submission): DocumentSend
{
    return app(TenantContext::class)->run(test()->s->org, fn () => DocumentSend::query()->where('submission_uuid', $submission->uuid)->sole());
}

function safetyVoid(Submission $submission): void
{
    app(TenantContext::class)->run(test()->s->org, function () use ($submission): void {
        app(TransitionSubmission::class)->handle($submission, SubmissionStatus::Completed, test()->s->owner);
        app(TransitionSubmission::class)->handle($submission, SubmissionStatus::Void, test()->s->owner);
    });
}

it('never mails a waiting request once the submission was voided', function (): void {
    $submission = safetySubmitted();
    safetySend($submission)->assertStatus(202)->assertJsonPath('data.send.status', 'waiting_for_document');
    safetyVoid($submission);

    $this->tenant->run($this->s->org, fn () => app(RenderSubmission::class)->handle($submission->refresh(), Rendition::REASON_RERENDER, $this->s->owner));

    $send = safetyRow($submission);
    expect($send->status)->toBe(DocumentSendStatus::Failed)
        ->and($send->failure_reason)->toBe(DocumentSendFailure::SubmissionVoid)
        ->and(MailFixtures::arrayTransport()->messages())->toHaveCount(0);
});

it('fails a waiting request whose requester lost the right to send', function (): void {
    $submission = safetySubmitted();
    safetySend($submission)->assertStatus(202);
    $this->s->worker->forceFill(['status' => UserStatus::Deactivated, 'deactivated_at' => now()])->save();

    $this->tenant->run($this->s->org, fn () => app(RenderSubmission::class)->handle($submission, Rendition::REASON_RERENDER, $this->s->owner));

    expect(safetyRow($submission)->failure_reason)->toBe(DocumentSendFailure::NotAllowed)
        ->and(MailFixtures::arrayTransport()->messages())->toHaveCount(0);
});

it('keeps a request waiting while the only rendition predates the latest submit', function (): void {
    $submission = safetySubmitted();
    $this->tenant->run($this->s->org, fn () => app(RenderSubmission::class)->handle($submission));
    $this->travel(1)->minutes();
    $this->tenant->run($this->s->org, fn () => $submission->forceFill(['submitted_at' => now()])->save()); // re-submitted after changes

    safetySend($submission)->assertStatus(202)->assertJsonPath('data.send.status', 'waiting_for_document');

    $this->travel(1)->minutes();
    $this->tenant->run($this->s->org, fn () => app(RenderSubmission::class)->handle($submission->refresh(), Rendition::REASON_RERENDER, $this->s->owner));
    expect(safetyRow($submission)->status)->toBe(DocumentSendStatus::Queued)
        ->and(MailFixtures::arrayTransport()->messages())->toHaveCount(1);
});

it('leaves a request that was queued meanwhile alone when the listener gives up', function (): void {
    $submission = safetySubmitted();
    safetySend($submission)->assertStatus(202);
    $this->tenant->run($this->s->org, fn () => app(RenderSubmission::class)->handle($submission));
    expect(safetyRow($submission)->status)->toBe(DocumentSendStatus::Queued);

    app(SendWaitingDocuments::class)->failed(new RenditionCreated(1, $submission->uuid, $this->s->org->id), new RuntimeException('late failure'));

    expect(safetyRow($submission)->status)->toBe(DocumentSendStatus::Queued);
});

it('does not deliver a queued customer mail after the submission was voided', function (): void {
    $submission = safetySubmitted();
    $this->tenant->run($this->s->org, fn () => app(RenderSubmission::class)->handle($submission));
    Queue::fake();
    safetySend($submission)->assertStatus(202)->assertJsonPath('data.send.status', 'queued');
    safetyVoid($submission);
    $message = $this->tenant->run($this->s->org, fn () => MailMessage::query()->sole());

    $this->tenant->run($this->s->org, fn () => app()->call([new DeliverMail($message->id), 'handle']));

    expect($this->tenant->run($this->s->org, fn () => $message->fresh()->status))->toBe(MailStatus::Failed)
        ->and(safetyRow($submission)->failure_reason)->toBe(DocumentSendFailure::SubmissionVoid)
        ->and(MailFixtures::arrayTransport()->messages())->toHaveCount(0);
});
