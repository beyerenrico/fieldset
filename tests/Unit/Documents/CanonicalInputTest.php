<?php

declare(strict_types=1);

use App\Domain\Documents\Branding\Branding;
use App\Domain\Documents\Designs\DesignSettings;
use App\Domain\Documents\Rendering\DocumentMeta;
use App\Domain\Documents\Rendering\DocumentRenderer;
use App\Domain\Documents\Rendering\HtmlComposer;
use App\Domain\Documents\Rendering\RenderAsset;
use App\Domain\Documents\Rendering\RenderInput;

function docSampleDefinition(): stdClass
{
    return json_decode((string) json_encode([
        'specVersion' => '1.0.0', 'key' => 'report', 'title' => 'Prüfbericht', 'locale' => 'de-DE',
        'pages' => [['key' => 'p1', 'title' => 'Seite', 'elements' => [
            ['id' => 'a', 'type' => 'text', 'key' => 'customer', 'label' => 'Kunde', 'pdf' => ['label' => 'Auftraggeber', 'width' => 'half']],
            ['id' => 'b', 'type' => 'text', 'key' => 'secret', 'label' => 'Intern', 'pdf' => ['hidden' => true]],
            ['id' => 'c', 'type' => 'text', 'key' => 'note', 'label' => 'Notiz'],
            ['id' => 'd', 'type' => 'repeater', 'key' => 'items', 'label' => 'Positionen', 'display' => 'table', 'pdf' => ['keepTogether' => true], 'elements' => [
                ['id' => 'e', 'type' => 'text', 'key' => 'description', 'label' => 'Beschreibung'],
                ['id' => 'f', 'type' => 'money', 'key' => 'price', 'label' => 'Preis', 'currency' => 'EUR'],
                ['id' => 's', 'type' => 'section', 'key' => 'internal', 'label' => 'Intern', 'pdf' => ['hidden' => true], 'elements' => [
                    ['id' => 't', 'type' => 'text', 'key' => 'cost', 'label' => 'Einkaufspreis'],
                ]],
            ]],
            ['id' => 'g', 'type' => 'heading', 'key' => 'h', 'text' => 'Nur wenn sichtbar'],
        ]]],
    ]));
}

function docSampleInput(array $data = [], array $design = [], array $meta = [], array $visibility = [], array $assets = []): RenderInput
{
    $data = json_decode((string) json_encode([
        'customer' => 'Müller & Söhne', 'secret' => 'TOPSECRET', 'items' => [['description' => 'Schalter', 'price' => '89.00', 'cost' => 'GEHEIM']], ...$data,
    ]));
    $meta = [
        'organizationName' => 'Acme <GmbH>', 'number' => 'PB-2026-00007', 'status' => 'submitted', 'submittedAt' => '2026-10-16T09:41:00+02:00',
        'timezone' => 'Europe/Berlin', 'formKey' => 'report', 'formTitle' => 'Prüfbericht', 'formVersion' => 3, 'formVersionId' => 30,
        'designVersion' => 1, 'designVersionId' => 10, 'rendererVersion' => DocumentRenderer::VERSION, ...$meta,
    ];

    return new RenderInput(
        docSampleDefinition(), $data, $visibility,
        new Branding('#0f766e', '#b45309', 'inter', null, 'Acme GmbH', ['legal_footer' => 'Acme GmbH · Amtsgericht Berlin']),
        DesignSettings::fromArray($design),
        new DocumentMeta(...$meta),
        $assets,
    );
}

function docInputHash(RenderInput $input): string
{
    return DocumentRenderer::inputHash($input, app(HtmlComposer::class)->compose($input));
}

it('is deterministic: the same input gives the same HTML and input hash', function (): void {
    $a = app(HtmlComposer::class)->compose(docSampleInput());
    $b = app(HtmlComposer::class)->compose(docSampleInput());

    expect($a)->toEqual($b)->and(docInputHash(docSampleInput()))->toBe(docInputHash(docSampleInput()))
        ->and(docInputHash(docSampleInput()))->toMatch('/^[0-9a-f]{64}$/');
});

it('changes the input hash when data, design, versions or assets change', function (): void {
    $base = docInputHash(docSampleInput());
    $asset = fn (string $sha): RenderAsset => new RenderAsset('photo', 'image/png', $sha, 'local', 'x.png');

    expect(docInputHash(docSampleInput(data: ['customer' => 'Other'])))->not->toBe($base)
        ->and(docInputHash(docSampleInput(design: ['paper' => 'letter'])))->not->toBe($base)
        ->and(docInputHash(docSampleInput(meta: ['designVersionId' => 11])))->not->toBe($base)
        ->and(docInputHash(docSampleInput(meta: ['formVersionId' => 31])))->not->toBe($base)
        ->and(docInputHash(docSampleInput(assets: ['u1' => $asset(str_repeat('a', 64))])))
        ->not->toBe(docInputHash(docSampleInput(assets: ['u1' => $asset(str_repeat('b', 64))])));
});

it('honours pdf hints, visibility and escapes every value', function (): void {
    $html = app(HtmlComposer::class)->compose(docSampleInput(visibility: ['/h' => false]))->body;

    expect($html)->toContain('Auftraggeber')->not->toContain('>Kunde<')
        ->and($html)->toContain('w-half')
        ->and($html)->not->toContain('TOPSECRET')
        ->and($html)->toContain('Müller &amp; Söhne')
        ->and($html)->toMatch('/class="node w-full keep repeater"/')
        ->and($html)->toContain('<thead>')->toContain("89,00\u{a0}€")
        ->and($html)->not->toContain('Einkaufspreis')->not->toContain('GEHEIM')   // pdf.hidden container inside a table row
        ->and($html)->not->toContain('Nur wenn sichtbar')
        ->and($html)->not->toContain('Notiz');                     // empty field hidden by default
});

it('shows empty fields, cards and watermarks when the design says so', function (): void {
    $html = app(HtmlComposer::class)->compose(docSampleInput(design: ['show_empty' => true, 'repeaters' => 'cards'], meta: ['status' => 'void']))->body;

    expect($html)->toContain('Notiz')->toContain('—')
        ->and($html)->toContain('class="card"')->not->toContain('<thead>')
        ->and($html)->toContain('class="watermark"')->toContain('UNGÜLTIG')
        ->and(app(HtmlComposer::class)->compose(docSampleInput())->body)->not->toContain('class="watermark"')
        ->and(app(HtmlComposer::class)->compose(docSampleInput(design: ['watermark' => 'none'], meta: ['status' => 'draft']))->body)->not->toContain('class="watermark"');
});

it('renders header and footer tokens as escaped text with page placeholders', function (): void {
    $html = app(HtmlComposer::class)->compose(docSampleInput(design: ['header' => '<p>{{org.name}} <script>x</script></p>']));

    expect($html->header)->toContain('Acme &lt;GmbH&gt;')->not->toContain('<script>')
        ->and($html->footer)->toContain('PB-2026-00007 · 16.10.2026 · <span class="pageNumber"></span> / <span class="totalPages"></span>')
        ->and($html->footer)->toContain('Amtsgericht Berlin')
        ->and($html->body)->toContain("@font-face{font-family:'Inter'")->not->toContain('http://')->not->toContain('https://');
});
