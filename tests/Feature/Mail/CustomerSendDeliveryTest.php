<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Documents\Events\RenditionCreated;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Renditions\GenerateRendition;
use App\Domain\Documents\Renditions\RenderSubmission;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Actions\DeliverCustomerSend;
use App\Domain\Mail\Enums\DocumentSendStatus;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Listeners\SendWaitingDocuments;
use App\Domain\Mail\Models\DocumentLink;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Feature\Mail\MailFixtures;
use Tests\Support\FieldAppScenario;

/* M3-T08: a send goes out with the rendition (attachment or link), or waits until the first rendition exists. */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    DocumentFixtures::fakeGotenberg();
    $this->s = FieldAppScenario::make();
    $this->tenant = app(TenantContext::class);
});

function deliverySend(Submission $submission, array $body = ['email' => 'kunde@example.com', 'name' => 'Frau Weber']): TestResponse
{
    $s = test()->s;

    return test()->withHeaders([...apiHeaders($s->worker, $s->org), 'Idempotency-Key' => (string) Str::uuid()])
        ->postJson("/api/v1/submissions/{$submission->uuid}/send", $body);
}

function deliverySubmitted(): Submission
{
    $s = test()->s;
    $submission = DocumentFixtures::submit($s, DocumentFixtures::draft($s));

    return app(TenantContext::class)->run($s->org, fn (): Submission => $submission->refresh());
}

function deliverySendRow(Submission $submission): DocumentSend
{
    return app(TenantContext::class)->run(test()->s->org, fn () => DocumentSend::query()->where('submission_uuid', $submission->uuid)->sole());
}

it('queues the mail at once when the PDF exists: attached, localized, logged, audited once', function (): void {
    $this->s->org->forceFill(['locale' => 'de'])->save();
    $submission = deliverySubmitted();

    deliverySend($submission)->assertStatus(202)->assertJsonPath('data.send.status', 'queued');

    $send = deliverySendRow($submission);
    $message = $this->tenant->run($this->s->org, fn () => MailMessage::query()->findOrFail($send->mail_message_id));
    $email = MailFixtures::lastSentEmail();
    $rendition = $this->tenant->run($this->s->org, fn () => Rendition::query()->where('submission_uuid', $submission->uuid)->sole());
    expect($send->status)->toBe(DocumentSendStatus::Queued)
        ->and($send->rendition_id)->toBe($rendition->id)
        ->and($message->status)->toBe(MailStatus::Sent)
        ->and($message->submission_uuid)->toBe($submission->uuid)
        ->and($message->subject)->toBe("Servicebericht {$submission->number} von Acme GmbH")
        ->and($email->getTo()[0]->getAddress())->toBe('kunde@example.com')
        ->and($email->getSubject())->toBe("Servicebericht {$submission->number} von Acme GmbH")
        ->and((string) $email->getHtmlBody())->toContain('Guten Tag Frau Weber,')->toContain('anbei erhalten Sie das Dokument')
        ->and($email->getAttachments())->toHaveCount(1)
        ->and($email->getAttachments()[0]->getFilename())->toBe($submission->number.'.pdf')
        ->and(Activity::query()->where('event', 'document.sent')->count())->toBe(1);
});

it('waits for the PDF and sends it when the first rendition is created', function (): void {
    config(['documents.render_on_submit' => false]);
    $submission = deliverySubmitted();

    deliverySend($submission, ['email' => 'kunde@example.com'])->assertStatus(202)->assertJsonPath('data.send.status', 'waiting_for_document');
    expect(MailFixtures::arrayTransport()->messages())->toHaveCount(0);

    $this->tenant->clear();
    new GenerateRendition($submission->uuid, $this->s->org->id)->handle(app(RenderSubmission::class), $this->tenant);

    $send = deliverySendRow($submission);
    $email = MailFixtures::lastSentEmail();
    expect($send->status)->toBe(DocumentSendStatus::Queued)
        ->and($send->mail_message_id)->not->toBeNull()
        ->and($email->getTo()[0]->getAddress())->toBe('kunde@example.com')
        ->and($email->getSubject())->toContain((string) $submission->number)
        ->and($email->getAttachments())->toHaveCount(1);
});

it('sends a PDF over the attachment limit as an expiring link', function (): void {
    config(['messaging.attachment_max_bytes' => 10]);
    $submission = deliverySubmitted();

    deliverySend($submission)->assertStatus(202);

    $email = MailFixtures::lastSentEmail();
    $link = $this->tenant->run($this->s->org, fn () => DocumentLink::query()->sole());
    expect($email->getAttachments())->toHaveCount(0)
        ->and($link->submission_uuid)->toBe($submission->uuid)
        ->and($link->filename)->toBe($submission->number.'.pdf')
        ->and((string) $email->getHtmlBody())->toContain('/documents/links/');
});

it('never sends one request twice', function (): void {
    config(['documents.render_on_submit' => false]);
    $submission = deliverySubmitted();
    deliverySend($submission)->assertStatus(202);
    $rendition = $this->tenant->run($this->s->org, fn () => app(RenderSubmission::class)->handle($submission));

    $this->tenant->run($this->s->org, fn () => app(DeliverCustomerSend::class)->handle(deliverySendRow($submission), $rendition));

    expect(MailFixtures::arrayTransport()->messages())->toHaveCount(1)
        ->and($this->tenant->run($this->s->org, fn () => MailMessage::query()->count()))->toBe(1);
});

it('announces only new renditions, not an automatic render that reused the latest one', function (): void {
    $submission = deliverySubmitted();
    Event::fake([RenditionCreated::class]);

    $this->tenant->run($this->s->org, fn () => app(RenderSubmission::class)->handle($submission));
    Event::assertNotDispatched(RenditionCreated::class);

    $this->tenant->run($this->s->org, fn () => app(RenderSubmission::class)->handle($submission, Rendition::REASON_RERENDER));
    Event::assertDispatched(RenditionCreated::class, fn (RenditionCreated $e): bool => $e->submissionUuid === $submission->uuid
        && $e->organizationId === $this->s->org->id);
});

it('marks waiting requests failed when the listener gives up', function (): void {
    config(['documents.render_on_submit' => false]);
    $submission = deliverySubmitted();
    deliverySend($submission)->assertStatus(202);
    $this->tenant->clear();

    app(SendWaitingDocuments::class)->failed(new RenditionCreated(1, $submission->uuid, $this->s->org->id), new RuntimeException('Disk unavailable'));

    $send = deliverySendRow($submission);
    expect($send->status)->toBe(DocumentSendStatus::Failed)->and($send->error)->toBe('Disk unavailable')
        ->and($this->tenant->check())->toBeFalse();
});
