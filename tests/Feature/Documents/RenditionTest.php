<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Documents\Designs\Actions\PublishDesign;
use App\Domain\Documents\Designs\DesignResolver;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Rendering\RenderBudgetExceededException;
use App\Domain\Documents\Renditions\GenerateRendition;
use App\Domain\Documents\Renditions\RenderSubmission;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Actions\TransitionSubmission;
use App\Domain\Submissions\Enums\SubmissionStatus;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Support\FieldAppScenario;

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    DocumentFixtures::fakeGotenberg(pages: 2);
    $this->s = FieldAppScenario::make();
    $this->tenant = app(TenantContext::class);
});

function docRenditionsOf(string $uuid): Collection
{
    return app(TenantContext::class)->run(test()->s->org, fn () => Rendition::query()->where('submission_uuid', $uuid)->orderBy('id')->get());
}

it('queues GenerateRendition after a submit commits', function (): void {
    Queue::fake();
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));

    Queue::assertPushed(GenerateRendition::class, fn (GenerateRendition $job): bool => $job->submissionUuid === $submission->uuid
        && $job->organizationId === $this->s->org->id && $job->reason === Rendition::REASON_SUBMITTED);
});

it('does not queue renders when render_on_submit is off', function (): void {
    Queue::fake();
    config(['documents.render_on_submit' => false]);
    DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));

    Queue::assertNothingPushed();
});

it('stores an immutable rendition with versions, hashes and page count on submit', function (): void {
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));
    $rendition = docRenditionsOf($submission->uuid)->sole();
    $file = (string) Storage::disk(DocumentFixtures::DISK)->get($rendition->path);

    expect($rendition->form_version_id)->toBe($this->s->version->id)
        ->and($rendition->design_version_id)->toBeInt()
        ->and($rendition->renderer_version)->toBe('1.0.0')
        ->and($rendition->page_count)->toBe(2)
        ->and($rendition->file_sha256)->toBe(hash('sha256', $file))
        ->and($rendition->size)->toBe(strlen($file))
        ->and($rendition->input_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($rendition->path)->toStartWith("renditions/{$this->s->org->id}/{$submission->uuid}/")
        ->and(Activity::query()->where('event', 'submission.pdf_generated')->count())->toBe(1);

    expect(fn () => $rendition->forceFill(['page_count' => 9])->save())->toThrow(LogicException::class)
        ->and(fn () => $rendition->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('renditions')->where('id', $rendition->id)->update(['page_count' => 9])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('renditions')->where('id', $rendition->id)->delete()))->toThrow(QueryException::class);
});

it('sends only inlined HTML to the renderer: paper, margins, header and footer from the design', function (): void {
    DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));
    $parts = DocumentFixtures::sentParts();

    expect(array_keys($parts))->toContain('index.html', 'header.html', 'footer.html')
        ->and($parts['paperWidth'])->toBe('8.27in')->and($parts['marginTop'])->toBe('28mm')
        ->and($parts['index.html'])->toContain('Beispiel GmbH')->toContain('data:image/png;base64,')
        ->and($parts['index.html'])->not->toMatch('#(src|href)="https?://#')
        ->and($parts['footer.html'])->toContain('class="pageNumber"');
});

it('re-renders into a new rendition with the same design version and input hash', function (): void {
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));
    $first = docRenditionsOf($submission->uuid)->sole();

    $this->tenant->run($this->s->org, function () use ($submission): void {
        $design = app(DesignResolver::class)->designFor($submission->formVersion->definition);
        app(PublishDesign::class)->handle($design, 'Landscape', $this->s->owner, [...$design->settings, 'orientation' => 'landscape']);
        app(RenderSubmission::class)->handle($submission->refresh(), Rendition::REASON_RERENDER, $this->s->owner);
    });
    [$a, $b] = docRenditionsOf($submission->uuid)->all();

    expect($b->id)->not->toBe($a->id)
        ->and($b->reason)->toBe(Rendition::REASON_RERENDER)
        ->and($b->created_by)->toBe($this->s->owner->id)
        ->and($b->design_version_id)->toBe($first->design_version_id)
        ->and($b->input_hash)->toBe($first->input_hash)
        ->and($b->file_sha256)->not->toBe($first->file_sha256)         // PDF bytes are never reproducible
        ->and($a->refresh()->file_sha256)->toBe($first->file_sha256);   // publishing never changes a rendition
});

it('changes the input hash when the submission changes, e.g. voiding adds the watermark', function (): void {
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));
    $this->tenant->run($this->s->org, function () use ($submission): void {
        app(TransitionSubmission::class)->handle($submission, SubmissionStatus::Completed, $this->s->owner);
        app(TransitionSubmission::class)->handle($submission, SubmissionStatus::Void, $this->s->owner);
        app(RenderSubmission::class)->handle($submission->refresh(), Rendition::REASON_RERENDER, $this->s->owner);
    });
    [$a, $b] = docRenditionsOf($submission->uuid)->all();

    expect($b->input_hash)->not->toBe($a->input_hash)
        ->and(DocumentFixtures::sentParts()['index.html'])->toContain('class="watermark"');
});

it('is idempotent for automatic renders of an unchanged submission', function (): void {
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));

    new GenerateRendition($submission->uuid, $this->s->org->id)->handle(app(RenderSubmission::class), $this->tenant);

    expect(docRenditionsOf($submission->uuid))->toHaveCount(1);
    Http::assertSentCount(1);
});

it('keeps the job timeout below the queue retry_after and never overlaps renders of one submission', function (): void {
    $job = new GenerateRendition('0192f1c4-7a1e-7c3b-9a55-1d2e3f4a5b6c', 1);
    $timeout = new ReflectionClass($job)->getAttributes(Timeout::class)[0]->newInstance()->timeout;

    expect($timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        ->and($timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and($job->middleware()[0])->toBeInstanceOf(WithoutOverlapping::class);
});

it('never renders drafts and restores the tenant from the job', function (): void {
    $draft = DocumentFixtures::draft($this->s);
    $this->tenant->clear();

    new GenerateRendition($draft->uuid, $this->s->org->id)->handle(app(RenderSubmission::class), $this->tenant);

    expect($this->tenant->check())->toBeFalse()->and(docRenditionsOf($draft->uuid))->toHaveCount(0);
});

it('fails a document over the size budget without storing anything', function (): void {
    config(['documents.max_request_bytes' => 10_000]);
    $submission = DocumentFixtures::draft($this->s);

    expect(fn () => $this->tenant->run($this->s->org, fn () => app(RenderSubmission::class)->handle(
        DocumentFixtures::submit($this->s, $submission)->refresh(), Rendition::REASON_RERENDER,
    )))->toThrow(RenderBudgetExceededException::class);
    expect(docRenditionsOf($submission->uuid))->toHaveCount(0)
        ->and(Storage::disk(DocumentFixtures::DISK)->allFiles('renditions'))->toBe([]);
    Http::assertNothingSent();
});

it('keeps the submit successful when the renderer is down', function (): void {
    DocumentFixtures::$gotenbergFailure = 503;
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));

    expect($submission->status)->toBe(SubmissionStatus::Submitted)->and($submission->number)->not->toBeNull()
        ->and(docRenditionsOf($submission->uuid))->toHaveCount(0);
});
