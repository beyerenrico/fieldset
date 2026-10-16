<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Identity\Actions\DeactivateUser;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Support\FieldAppScenario;

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    DocumentFixtures::fakeGotenberg();
    $this->s = FieldAppScenario::make();
});

function docDocumentUrl(string $uuid): string
{
    return "/api/v1/submissions/{$uuid}/document";
}

it('answers 404 document_not_ready until a rendition exists', function (): void {
    config(['documents.render_on_submit' => false]);
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));

    $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))->getJson(docDocumentUrl($submission->uuid))
        ->assertNotFound()->assertJsonPath('error.code', 'document_not_ready');
});

it('returns a short-lived signed URL of the latest rendition that downloads the PDF', function (): void {
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));
    $rendition = app(TenantContext::class)->run($this->s->org, fn () => Rendition::query()->where('submission_uuid', $submission->uuid)->sole());

    $response = $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))->getJson(docDocumentUrl($submission->uuid))
        ->assertOk()
        ->assertJsonPath('data.document.rendition.uuid', $rendition->uuid)
        ->assertJsonPath('data.document.rendition.design_version_id', $rendition->design_version_id)
        ->assertJsonPath('data.document.rendition.file_sha256', $rendition->file_sha256);
    $url = (string) $response->json('data.document.url');
    expect(Carbon::parse((string) $response->json('data.document.expires_at'))->diffInMinutes(now(), true))->toBeLessThanOrEqual(5.1);

    $download = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($download->headers->get('Content-Disposition'))->toContain($submission->number.'.pdf')
        ->and($download->streamedContent())->toBe(Storage::disk(DocumentFixtures::DISK)->get($rendition->path))
        ->and(Activity::query()->where('event', 'submission.pdf_downloaded')->sole()->causer_id)->toBe($this->s->worker->id);
});

it('rejects tampered and expired download links', function (): void {
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));
    $url = (string) $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))->getJson(docDocumentUrl($submission->uuid))->json('data.document.url');

    $this->get(str_replace('user='.$this->s->worker->id, 'user='.$this->s->owner->id, $url))->assertForbidden();
    $this->travel(6)->minutes();
    $this->get($url)->assertForbidden();
});

it('re-checks access on download: a deactivated user gets 404', function (): void {
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));
    $url = (string) $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))->getJson(docDocumentUrl($submission->uuid))->json('data.document.url');

    app(DeactivateUser::class)->handle($this->s->worker, $this->s->owner);

    $this->get($url)->assertNotFound();
});

it('follows submission visibility: another field worker without a sharing group gets 404', function (): void {
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));
    $stranger = memberOf($this->s->org, SystemRole::FieldWorker);

    $this->withHeaders(apiHeaders($stranger, $this->s->org))->getJson(docDocumentUrl($submission->uuid))
        ->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->withHeaders(apiHeaders($this->s->owner, $this->s->org))->getJson(docDocumentUrl($submission->uuid))->assertOk();
});

it('never serves another organization\'s document', function (): void {
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));
    $other = makeOrganization(name: 'Other');

    $this->withHeaders(apiHeaders(ownerOf($other), $other))->getJson(docDocumentUrl($submission->uuid))->assertNotFound();
    $this->withHeaders(apiHeaders(ownerOf($other), $this->s->org))->getJson(docDocumentUrl($submission->uuid))->assertForbidden();
});
