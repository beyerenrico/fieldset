<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Rendition;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Privacy\Retention\AnonymizeSubmission;
use App\Domain\Submissions\Models\Submission;
use App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Support\FieldAppScenario;

/* M8-T08: the PDF files of an anonymized submission are gone (ADR 0039); every download answers 410 without reporting. */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    DocumentFixtures::fakeGotenberg();
    app()->setLocale('en');
    $this->s = FieldAppScenario::make();
    $this->submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));
    $this->rendition = app(TenantContext::class)->run($this->s->org, fn () => Rendition::query()->where('submission_uuid', $this->submission->uuid)->sole());
});

function anonDocShowUrl(Submission $submission): string
{
    return "/api/v1/submissions/{$submission->uuid}/document";
}

function anonDocAnonymize(object $test): void
{
    $anonymized = app(TenantContext::class)->run($test->s->org, fn (): bool => app(AnonymizeSubmission::class)->handle($test->submission, 'request', $test->s->owner));
    expect($anonymized)->toBeTrue()
        ->and(Storage::disk(DocumentFixtures::DISK)->exists($test->rendition->path))->toBeFalse();
}

function anonDocAdminUrl(object $test): string
{
    return route('admin.renditions.download', ['organization' => $test->s->org->slug, 'rendition' => $test->rendition->uuid]);
}

it('answers 410 document_anonymized for the document of an anonymized submission, without reporting', function (): void {
    anonDocAnonymize($this);
    Exceptions::fake();

    $this->withHeaders(apiHeaders($this->s->owner, $this->s->org))->getJson(anonDocShowUrl($this->submission))
        ->assertStatus(410)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'document_anonymized')
        ->assertJsonPath('error.message', __('documents.errors.anonymized'))
        ->assertJsonMissingPath('data.document');

    Exceptions::assertNothingReported();
});

it('answers 410 document_anonymized on a signed download issued before the anonymization, without reporting', function (): void {
    $url = (string) $this->withHeaders(apiHeaders($this->s->owner, $this->s->org))->getJson(anonDocShowUrl($this->submission))->json('data.document.url');
    anonDocAnonymize($this);
    Exceptions::fake();

    $this->get($url)
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'document_anonymized')
        ->assertJsonPath('error.message', __('documents.errors.anonymized'));

    Exceptions::assertNothingReported();
});

it('still answers 404 and reports a missing PDF file of a submission that is not anonymized', function (): void {
    $url = (string) $this->withHeaders(apiHeaders($this->s->owner, $this->s->org))->getJson(anonDocShowUrl($this->submission))->json('data.document.url');
    Storage::disk(DocumentFixtures::DISK)->delete($this->rendition->path);
    Exceptions::fake();

    $this->get($url)->assertNotFound()->assertJsonPath('error.code', 'not_found');

    Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'Rendition file missing'));
});

it('answers 410 with the message on the admin download of an anonymized submission, without reporting', function (): void {
    anonDocAnonymize($this);
    Exceptions::fake();

    $this->actingAs($this->s->owner)->get(anonDocAdminUrl($this))
        ->assertStatus(410)
        ->assertSee(__('documents.errors.anonymized'));

    Exceptions::assertNothingReported();
});

it('shows the renditions of an anonymized submission without download links, with a note', function (): void {
    anonDocAnonymize($this);

    actingInAdmin($this->s->owner, $this->s->org);
    Livewire::test(ViewSubmission::class, ['record' => $this->submission->uuid])
        ->assertOk()
        ->assertSee(__('documents.errors.anonymized'))
        ->assertSeeHtml('data-section="documents-anonymized"')
        ->assertSeeHtml('data-rendition="'.$this->rendition->uuid.'"')
        ->assertDontSeeHtml(anonDocAdminUrl($this));
});

it('keeps the download link and shows no note for a submission that is not anonymized', function (): void {
    actingInAdmin($this->s->owner, $this->s->org);
    Livewire::test(ViewSubmission::class, ['record' => $this->submission->uuid])
        ->assertOk()
        ->assertDontSeeHtml('data-section="documents-anonymized"')
        ->assertSeeHtml(anonDocAdminUrl($this));
});
