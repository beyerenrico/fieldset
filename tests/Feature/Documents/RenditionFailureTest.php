<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Models\RenditionFailure;
use App\Domain\Documents\Rendering\RenderFailedException;
use App\Domain\Documents\Renditions\GenerateRendition;
use App\Domain\Documents\Renditions\QueueRenditionOnSubmit;
use App\Domain\Documents\Renditions\RenderSubmission;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Enums\DocumentSendFailure;
use App\Domain\Mail\Enums\DocumentSendStatus;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Submissions\Events\SubmissionSubmitted;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Str;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Support\FieldAppScenario;

/* M3 review #1: permanent render failures are recorded, visible and fail waiting sends (ADR 0031). */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    DocumentFixtures::fakeGotenberg();
    $this->s = FieldAppScenario::make();
    $this->tenant = app(TenantContext::class);
});

function failureSubmitted(): Submission
{
    $s = test()->s;
    $submission = DocumentFixtures::submit($s, DocumentFixtures::draft($s));

    return app(TenantContext::class)->run($s->org, fn (): Submission => $submission->refresh());
}

function failureOf(Submission $submission): ?RenditionFailure
{
    return app(TenantContext::class)->run(test()->s->org, fn () => RenditionFailure::query()->where('submission_uuid', $submission->uuid)->first());
}

function failureRequestSend(Submission $submission): void
{
    $s = test()->s;
    test()->withHeaders([...apiHeaders($s->worker, $s->org), 'Idempotency-Key' => (string) Str::uuid()])
        ->postJson("/api/v1/submissions/{$submission->uuid}/send", ['email' => 'kunde@example.com'])
        ->assertStatus(202)->assertJsonPath('data.send.status', 'waiting_for_document');
}

it('records a permanent failure, audits it once and fails waiting sends with a fixed reason', function (): void {
    config(['documents.render_on_submit' => false]);
    $submission = failureSubmitted();
    failureRequestSend($submission);
    $this->tenant->clear();

    new GenerateRendition($submission->uuid, $this->s->org->id)
        ->failed(RenderFailedException::because(new RuntimeException('cURL error 7: http://admin:hunter2@gotenberg:3000 refused')));

    $failure = failureOf($submission);
    $send = $this->tenant->run($this->s->org, fn () => DocumentSend::query()->where('submission_uuid', $submission->uuid)->sole());
    expect($failure)->not->toBeNull()
        ->and($failure->reason)->toBe(RenditionFailure::RENDER_FAILED)
        ->and($failure->message)->toContain('cURL error 7')->not->toContain('hunter2')
        ->and($failure->attempts)->toBe(1)
        ->and($send->status)->toBe(DocumentSendStatus::Failed)
        ->and($send->failure_reason)->toBe(DocumentSendFailure::DocumentFailed)
        ->and(Activity::query()->where('event', 'submission.pdf_failed')->count())->toBe(1)
        ->and($this->tenant->check())->toBeFalse();
});

it('records budget_exceeded when the document is over the request budget', function (): void {
    config(['documents.max_request_bytes' => 10_000]);
    $submission = failureSubmitted();

    expect(failureOf($submission)?->reason)->toBe(RenditionFailure::BUDGET_EXCEEDED)
        ->and(Activity::query()->where('event', 'submission.pdf_failed')->sole()->properties['reason'])->toBe(RenditionFailure::BUDGET_EXCEEDED);
});

it('answers 409 document_failed with a fixed message instead of 404 after a permanent failure', function (): void {
    config(['documents.max_request_bytes' => 10_000]);
    $submission = failureSubmitted();

    $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))->getJson("/api/v1/submissions/{$submission->uuid}/document")
        ->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'document_failed')
        ->assertJsonPath('error.message', __('documents.errors.failed.budget_exceeded'));
});

it('counts repeated failures and clears the record when a later render succeeds', function (): void {
    config(['documents.render_on_submit' => false]);
    $submission = failureSubmitted();
    $job = new GenerateRendition($submission->uuid, $this->s->org->id);
    $job->failed(RenderFailedException::because(new RuntimeException('503')));
    $job->failed(RenderFailedException::because(new RuntimeException('503 again')));
    expect(failureOf($submission)?->attempts)->toBe(2)->and(failureOf($submission)?->message)->toContain('503 again');

    $this->tenant->run($this->s->org, fn () => app(RenderSubmission::class)->handle($submission, Rendition::REASON_RERENDER, $this->s->owner));

    expect(failureOf($submission))->toBeNull();
});

it('clears the failure when the submission is submitted again', function (): void {
    config(['documents.render_on_submit' => false]);
    $submission = failureSubmitted();
    new GenerateRendition($submission->uuid, $this->s->org->id)->failed(RenderFailedException::because(new RuntimeException('503')));
    config(['documents.render_on_submit' => true]);

    app(QueueRenditionOnSubmit::class)->handle(new SubmissionSubmitted($submission->uuid, $this->s->org->id));

    expect(failureOf($submission))->toBeNull()
        ->and($this->tenant->run($this->s->org, fn () => Rendition::query()->where('submission_uuid', $submission->uuid)->count()))->toBe(1);
});
