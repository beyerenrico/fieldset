<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Rendition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\CustomerSend\CustomerSendGate;
use App\Domain\Mail\Enums\DocumentSendFailure;
use App\Domain\Mail\Models\DocumentLink;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Privacy\Retention\AnonymizeSubmission;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Triggers\StartWorkflows;
use App\Domain\Workflows\Triggers\TriggerEvent;
use App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Feature\Workflows\WorkflowFixtures as W;
use Tests\Support\FieldAppScenario;

/*
 * The PDF files of an anonymized submission are deleted (ADR 0039), so nothing may promise them to a customer:
 * the API answers 410 document_anonymized, the admin action is not offered, a waiting request fails, and a
 * workflow email with the document fails its step with that reason instead of crashing in DeliverMail.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    DocumentFixtures::fakeGotenberg();
    app()->setLocale('en');
    $this->s = FieldAppScenario::make();
    $this->tenant = app(TenantContext::class);
    $this->submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));
    $this->rendition = $this->tenant->run($this->s->org, fn () => Rendition::query()->where('submission_uuid', $this->submission->uuid)->sole());
});

function anonymizedSendAnonymize(object $test): Submission
{
    $test->tenant->run($test->s->org, fn (): bool => app(AnonymizeSubmission::class)->handle($test->submission, 'request', $test->s->owner));
    expect(Storage::disk(DocumentFixtures::DISK)->exists($test->rendition->path))->toBeFalse();

    return $test->tenant->run($test->s->org, fn (): Submission => Submission::query()->findOrFail($test->submission->uuid));
}

/** @return array{sends: int, mails: int, links: int} customer sends, mails to the customer and document links */
function anonymizedSendCounts(object $test): array
{
    return $test->tenant->run($test->s->org, fn (): array => [
        'sends' => DocumentSend::query()->count(), 'mails' => MailMessage::query()->where('recipient_email', 'kunde@example.com')->count(), 'links' => DocumentLink::query()->count(),
    ]);
}

it('is allowed before the anonymization (control)', function (): void {
    expect(CustomerSendGate::allows($this->s->owner, $this->submission))->toBeTrue();
});

it('answers POST /submissions/{uuid}/send with 410 document_anonymized, without a send, a mail or a report', function (): void {
    anonymizedSendAnonymize($this);
    Exceptions::fake();

    $this->withHeaders([...apiHeaders($this->s->owner, $this->s->org), 'Idempotency-Key' => (string) Str::uuid()])
        ->postJson("/api/v1/submissions/{$this->submission->uuid}/send", ['email' => 'kunde@example.com'])
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'document_anonymized')
        ->assertJsonPath('error.message', __('documents.errors.anonymized'));

    expect(anonymizedSendCounts($this))->toBe(['sends' => 0, 'mails' => 0, 'links' => 0]);
    Exceptions::assertNothingReported();
});

it('no longer offers "Send to customer" in the admin inbox, and the submission says it cannot be sent', function (): void {
    $submission = anonymizedSendAnonymize($this);

    actingInAdmin($this->s->owner, $this->s->org);
    Livewire::test(ViewSubmission::class, ['record' => $this->submission->uuid])->assertOk()->assertActionHidden('sendToCustomer');

    expect(CustomerSendGate::allows($this->s->owner, $submission))->toBeFalse()
        ->and($this->s->owner->can('sendToCustomer', $submission))->toBeFalse()
        ->and(anonymizedSendCounts($this))->toBe(['sends' => 0, 'mails' => 0, 'links' => 0]);
});

it('fails a request that was still waiting when the submission was anonymized, without queuing a mail', function (): void {
    $refusal = fn (?User $requester, Submission $submission): ?DocumentSendFailure => $this->tenant->run(
        $this->s->org, fn (): ?DocumentSendFailure => CustomerSendGate::deliveryRefusal($requester, $submission),
    );
    expect($refusal($this->s->owner, $this->submission))->toBeNull();

    $submission = anonymizedSendAnonymize($this);

    expect($refusal($this->s->owner, $submission))->toBe(DocumentSendFailure::NotAllowed)
        ->and($refusal(null, $submission))->toBe(DocumentSendFailure::NotAllowed);
});

it('fails a workflow email with the document (attachment or link) with the anonymization reason', function (string $mode): void {
    $workflow = W::workflow($this->s->org, [W::step('send_email', [
        'to_addresses' => ['kunde@example.com'], 'subject' => 'Ihr Bericht', 'body' => 'Siehe {{ document.link }}', 'document' => $mode,
    ])], TriggerType::Manual);
    anonymizedSendAnonymize($this);

    $this->tenant->run($this->s->org, fn () => app(StartWorkflows::class)->handle(TriggerEvent::manual($workflow, $this->submission->uuid, $this->s->owner->id)));

    $run = W::runs($this->s->org)->sole();
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error)->toBe(__('documents.errors.anonymized'))
        ->and(anonymizedSendCounts($this))->toBe(['sends' => 0, 'mails' => 0, 'links' => 0]);
})->with(['attachment', 'link']);

it('still sends a workflow email without the document for an anonymized submission', function (): void {
    $workflow = W::workflow($this->s->org, [W::step('send_email', [
        'to_addresses' => ['kunde@example.com'], 'subject' => 'Erledigt', 'document' => 'none',
    ])], TriggerType::Manual);
    anonymizedSendAnonymize($this);

    $this->tenant->run($this->s->org, fn () => app(StartWorkflows::class)->handle(TriggerEvent::manual($workflow, $this->submission->uuid, $this->s->owner->id)));

    expect(W::runs($this->s->org)->sole()->status)->toBe(RunStatus::Completed)
        ->and(anonymizedSendCounts($this)['mails'])->toBe(1);
});
