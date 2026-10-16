<?php

declare(strict_types=1);

use App\Domain\Documents\Designs\Actions\PublishDesign;
use App\Domain\Documents\Designs\DesignResolver;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Rendering\PdfInfo;
use App\Domain\Documents\Renditions\RenderSubmission;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Support\FieldAppScenario;

/*
 * Integration against the real Gotenberg (GOTENBERG_URL; docker compose service `gotenberg`).
 * BUILD_PROMPT §8.5 acceptance criteria.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    $response = Http::timeout(5)->get(rtrim((string) config('laravel-pdf.gotenberg.url'), '/').'/health');
    expect($response->successful())->toBeTrue('Gotenberg is not reachable at '.config('laravel-pdf.gotenberg.url'));
    $this->s = FieldAppScenario::make();
});

it('renders a 60-line repeater across several pages with repeated headers and correct totals', function (): void {
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s, lines: 60));
    $rendition = app(TenantContext::class)->run($this->s->org, fn () => Rendition::query()->where('submission_uuid', $submission->uuid)->sole());

    $pdf = Storage::disk(DocumentFixtures::DISK)->get($rendition->path);
    $text = DocumentFixtures::pdfText((string) $pdf);
    $pages = substr_count($text, "\f") + (str_ends_with($text, "\f") ? 0 : 1);
    $tablePages = preg_match_all('/Beschreibung\s+Menge\s+Einheit\s+Einzelpreis\s+Gesamt/u', $text);

    expect($rendition->page_count)->toBeGreaterThan(1)
        ->and($rendition->page_count)->toBe(PdfInfo::pageCount((string) $pdf))
        ->and(DocumentFixtures::pdfInfo((string) $pdf))->toMatch('/Pages:\s+'.$rendition->page_count.'\b/')
        ->and($pages)->toBe($rendition->page_count)
        ->and($tablePages)->toBeGreaterThan(1)                       // the header row repeats on every table page
        ->and($text)->toContain('Line 1 ')->toContain('Line 60')    // nothing truncated
        ->and($text)->toContain('97,50 €')                           // line total 1.5 × 65.00
        ->and($text)->toContain('5.850,00 €')                        // net total of 60 lines
        ->and($text)->toContain('1.111,50 €')                        // VAT 19 %
        ->and($text)->toContain('6.961,50 €')                        // gross
        ->and($text)->toContain($submission->number)                 // header/footer token
        ->and($rendition->file_sha256)->toBe(hash('sha256', (string) $pdf));
});

it('produces a tagged PDF with page numbers while the renderer runs without JavaScript', function (): void {
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s, lines: 30));
    $rendition = app(TenantContext::class)->run($this->s->org, fn () => Rendition::query()->where('submission_uuid', $submission->uuid)->sole());
    $pdf = (string) Storage::disk(DocumentFixtures::DISK)->get($rendition->path);
    $text = DocumentFixtures::pdfText($pdf);

    expect(DocumentFixtures::pdfInfo($pdf))->toMatch('/Tagged:\s+yes/')
        ->and($rendition->page_count)->toBeGreaterThan(1)
        ->and($text)->toMatch('/\b1\s*\/\s*'.$rendition->page_count.'\b/')              // footer "{{page}} / {{pages}}"
        ->and($text)->toMatch('/\b'.$rendition->page_count.'\s*\/\s*'.$rendition->page_count.'\b/');
});

it('re-renders an old submission with its own design version after the design changed', function (): void {
    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));
    $tenant = app(TenantContext::class);
    $first = $tenant->run($this->s->org, fn () => Rendition::query()->where('submission_uuid', $submission->uuid)->sole());
    $originalText = DocumentFixtures::pdfText((string) Storage::disk(DocumentFixtures::DISK)->get($first->path));

    $tenant->run($this->s->org, function () use ($submission): void {
        $design = app(DesignResolver::class)->designFor($submission->formVersion->definition);
        app(PublishDesign::class)->handle($design, 'New letterhead', $this->s->owner, [
            ...$design->settings, 'paper' => 'letter', 'orientation' => 'landscape', 'header' => '<p>NEUER BRIEFKOPF</p>',
        ]);
    });
    $second = $tenant->run($this->s->org, fn () => app(RenderSubmission::class)->handle($submission->refresh(), Rendition::REASON_RERENDER, $this->s->owner));
    $pdf = (string) Storage::disk(DocumentFixtures::DISK)->get($second->path);

    expect($second->id)->not->toBe($first->id)
        ->and($second->design_version_id)->toBe($first->design_version_id)
        ->and($second->input_hash)->toBe($first->input_hash)
        ->and(DocumentFixtures::pdfText($pdf))->not->toContain('NEUER BRIEFKOPF')->toContain($this->s->org->name)
        ->and(DocumentFixtures::pdfInfo($pdf))->toMatch('/Page size:\s+595\.\d+ x 841\.\d+ pts \(A4\)/')
        ->and(DocumentFixtures::pdfText($pdf))->toBe($originalText);
});

it('uses the new design version for a new submission', function (): void {
    $tenant = app(TenantContext::class);
    $tenant->run($this->s->org, function (): void {
        $design = app(DesignResolver::class)->designFor($this->s->version->definition);
        app(PublishDesign::class)->handle($design, 'Letter', $this->s->owner, [...$design->settings, 'paper' => 'letter', 'header' => '<p>NEUER BRIEFKOPF</p>']);
    });

    $submission = DocumentFixtures::submit($this->s, DocumentFixtures::draft($this->s));
    $rendition = $tenant->run($this->s->org, fn () => Rendition::query()->where('submission_uuid', $submission->uuid)->sole());
    $pdf = (string) Storage::disk(DocumentFixtures::DISK)->get($rendition->path);

    expect(DocumentFixtures::pdfText($pdf))->toContain('NEUER BRIEFKOPF')
        ->and(DocumentFixtures::pdfInfo($pdf))->toMatch('/Page size:\s+612 x 792 pts \(letter\)/');
});
