<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Models\RenditionFailure;
use App\Domain\Documents\Renditions\GenerateRendition;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Enums\DocumentSendFailure;
use App\Domain\Mail\Enums\DocumentSendStatus;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Submissions\Actions\TransitionSubmission;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Support\FieldAppScenario;

/* M3 review #1: a scheduled sweep re-dispatches renders that never ran and closes sends of voided submissions. */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    DocumentFixtures::fakeGotenberg();
    config(['documents.render_on_submit' => false]); // simulates a lost dispatch
    $this->s = FieldAppScenario::make();
    $this->tenant = app(TenantContext::class);
});

function sweepSubmitted(): Submission
{
    $s = test()->s;
    $submission = DocumentFixtures::submit($s, DocumentFixtures::draft($s));

    return app(TenantContext::class)->run($s->org, fn (): Submission => $submission->refresh());
}

it('is scheduled', function (): void {
    $events = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);

    expect($events->contains(fn (string $command): bool => str_contains($command, 'documents:sweep')))->toBeTrue();
});

it('re-dispatches only old, submitted documents without a current rendition and without a recorded failure', function (): void {
    $missing = sweepSubmitted();
    $failed = sweepSubmitted();
    $rendered = sweepSubmitted();
    $draft = DocumentFixtures::draft($this->s);
    $this->tenant->run($this->s->org, function () use ($failed, $rendered): void {
        RenditionFailure::factory()->create(['submission_uuid' => $failed->uuid]);
        Rendition::factory()->create(['submission_uuid' => $rendered->uuid, 'created_at' => now()]);
    });
    $this->travel(15)->minutes();
    $fresh = sweepSubmitted();
    config(['documents.render_on_submit' => true]);
    Queue::fake();
    $this->tenant->clear();

    $this->artisan('documents:sweep')->assertSuccessful();

    Queue::assertPushed(GenerateRendition::class, 1);
    Queue::assertPushed(GenerateRendition::class, fn (GenerateRendition $job): bool => $job->submissionUuid === $missing->uuid
        && $job->organizationId === $this->s->org->id && $job->reason === Rendition::REASON_SUBMITTED);
    expect([$failed->uuid, $rendered->uuid, $draft->uuid, $fresh->uuid])->not->toContain(Queue::pushed(GenerateRendition::class)->first()->submissionUuid);
});

it('treats a rendition older than the latest submit as missing and waits a cool-down before sweeping again', function (): void {
    $submission = sweepSubmitted();
    $this->tenant->run($this->s->org, fn () => Rendition::factory()->create(['submission_uuid' => $submission->uuid, 'created_at' => now()->subHour()]));
    $this->travel(15)->minutes();
    config(['documents.render_on_submit' => true]);
    Queue::fake();

    $this->artisan('documents:sweep')->assertSuccessful();
    $this->artisan('documents:sweep')->assertSuccessful();

    Queue::assertPushed(GenerateRendition::class, 1);
});

it('does nothing while automatic rendering is off', function (): void {
    sweepSubmitted();
    $this->travel(15)->minutes();
    Queue::fake();

    $this->artisan('documents:sweep')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('fails waiting sends of voided submissions', function (): void {
    $submission = sweepSubmitted();
    $send = $this->tenant->run($this->s->org, function () use ($submission): DocumentSend {
        $send = DocumentSend::factory()->create(['submission_uuid' => $submission->uuid]);
        app(TransitionSubmission::class)->handle($submission, SubmissionStatus::Completed, $this->s->owner);
        app(TransitionSubmission::class)->handle($submission, SubmissionStatus::Void, $this->s->owner);

        return $send;
    });

    $this->artisan('documents:sweep')->assertSuccessful();

    $send = $this->tenant->run($this->s->org, fn () => $send->refresh());
    expect($send->status)->toBe(DocumentSendStatus::Failed)->and($send->failure_reason)->toBe(DocumentSendFailure::SubmissionVoid);
});
