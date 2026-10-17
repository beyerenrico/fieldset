<?php

declare(strict_types=1);

use App\Domain\Documents\Designs\Actions\CreateDesign;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Renditions\RenderSubmission;
use App\Domain\Documents\Renditions\RenditionLinks;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Actions\TransitionSubmission;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Str;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Support\AuditTrail;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

/*
 * §8.11 AC, part 4 (submission lifecycle): created, submitted, status changes, PDF generated, downloaded and
 * sent each write exactly one entry. Anonymization and deletion by retention: tests/Feature/Privacy/RetentionTest.
 */

beforeEach(function (): void {
    AuditTrail::reset();
    DocumentFixtures::fakeDisk();
    DocumentFixtures::fakeGotenberg();
    config(['documents.render_on_submit' => false]);
    $this->s = FieldAppScenario::make();
    $this->tenant = app(TenantContext::class);
    $this->tenant->run($this->s->org, fn () => app(CreateDesign::class)->handle('Standard', $this->s->owner)); // else the first render creates it
});

it('writes one entry when a submission is created and one when it is submitted; draft saves write none', function (): void {
    $uuid = FieldAppScenario::uuid();
    $signature = FieldAppScenario::uuid();
    $data = FieldAppScenario::serviceReportData($signature);

    expect(AuditTrail::of(fn () => FieldAppApi::put($this->s, $this->s->worker, $uuid, $data)->assertCreated()))->toBe(['submission.created']);
    FieldAppApi::signature($this->s, $this->s->worker, $uuid, $signature)->assertCreated();
    expect(AuditTrail::of(fn () => FieldAppApi::put($this->s, $this->s->worker, $uuid, [...$data, 'defect' => 'Changed'], 1)->assertOk()))->toBe([])
        ->and(AuditTrail::of(fn () => FieldAppApi::submit($this->s, $this->s->worker, $uuid, 2)->assertOk()))->toBe(['submission.submitted']);
});

it('writes one entry per status change', function (): void {
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));

    $this->tenant->run($this->s->org, function () use ($submission): void {
        expect(AuditTrail::of(fn () => app(TransitionSubmission::class)->handle($submission->refresh(), SubmissionStatus::InReview, $this->s->owner)))
            ->toBe(['submission.status_changed'])
            // Approval and the system's completion after it (§6.5 "approved → completed") are two status changes.
            ->and(AuditTrail::of(fn () => app(TransitionSubmission::class)->handle($submission->refresh(), SubmissionStatus::Approved, $this->s->owner, null, 'submission.approved')))
            ->toBe(['submission.approved', 'submission.completed'])
            ->and(AuditTrail::of(fn () => app(TransitionSubmission::class)->handle($submission->refresh(), SubmissionStatus::Void, $this->s->owner, 'Duplicate')))
            ->toBe(['submission.status_changed']);
    });
});

it('writes one entry when a PDF is generated, downloaded and sent to the customer', function (): void {
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));

    expect(AuditTrail::of(fn () => $this->tenant->run($this->s->org, fn () => app(RenderSubmission::class)->handle($submission->refresh(), Rendition::REASON_RERENDER, $this->s->owner))))
        ->toBe(['submission.pdf_generated']);
    $rendition = $this->tenant->run($this->s->org, fn () => Rendition::query()->where('submission_uuid', $submission->uuid)->sole());

    expect(AuditTrail::of(fn () => $this->get(RenditionLinks::temporaryUrl($rendition, $this->s->owner))->assertOk()))->toBe(['submission.pdf_downloaded'])
        ->and(AuditTrail::of(fn () => $this->withHeaders([...apiHeaders($this->s->worker, $this->s->org), 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/submissions/{$submission->uuid}/send", ['email' => 'kunde@example.com', 'name' => 'Frau Weber'])->assertStatus(202)))
        ->toBe(['document.sent']);
    expect($this->tenant->run($this->s->org, fn () => Submission::query()->findOrFail($submission->uuid)->status))->toBe(SubmissionStatus::Submitted);
});
