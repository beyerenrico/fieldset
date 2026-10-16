<?php

declare(strict_types=1);

use App\Domain\Documents\Branding\Branding;
use App\Domain\Documents\Designs\DesignSettings;
use App\Domain\Documents\Rendering\ComposedHtml;
use App\Domain\Documents\Rendering\DocumentMeta;
use App\Domain\Documents\Rendering\DocumentRenderer;
use App\Domain\Documents\Rendering\HtmlComposer;
use App\Domain\Documents\Rendering\RenderAsset;
use App\Domain\Documents\Rendering\RenderInput;
use Tests\Feature\Documents\DocumentFixtures;

/* M3 review #13–#16: semantic, accessible print HTML (tagged PDF), brand contrast, compact layout, table repeaters. */

const SEM_PHOTO = '0192f1c4-7a1e-7c3b-9a55-1d2e3f4a5b01';
const SEM_PHOTO_2 = '0192f1c4-7a1e-7c3b-9a55-1d2e3f4a5b02';
const SEM_SIGNATURE = '0192f1c4-7a1e-7c3b-9a55-1d2e3f4a5b03';

/** @param  list<array<string, mixed>>  $pages */
function semInput(array $pages, array $data = [], array $design = [], string $locale = 'de-DE', string $primary = '#0f766e', string $status = 'submitted'): RenderInput
{
    $definition = json_decode((string) json_encode(['specVersion' => '1.0.0', 'key' => 'r', 'title' => 'Bericht', 'locale' => $locale, 'pages' => $pages]));
    $png = DocumentFixtures::signaturePng();
    $assets = [
        SEM_PHOTO => RenderAsset::fromBytes('photo', 'image/png', $png),
        SEM_PHOTO_2 => RenderAsset::fromBytes('photo', 'image/png', $png.' '),
        SEM_SIGNATURE => RenderAsset::fromBytes('signature', 'image/png', $png),
    ];

    return new RenderInput(
        $definition, json_decode((string) json_encode((object) $data)), [],
        new Branding($primary, '#facc15', 'inter', null, 'Acme GmbH', []),
        DesignSettings::fromArray($design),
        new DocumentMeta('Acme', 'B-1', $status, '2026-10-16T09:41:00+02:00', 'Europe/Berlin', 'r', 'Bericht', 1, 1, 1, 1, DocumentRenderer::VERSION),
        $assets,
    );
}

function semCompose(RenderInput $input): ComposedHtml
{
    return app(HtmlComposer::class)->compose($input);
}

function semSinglePage(): array
{
    return [['key' => 'p1', 'title' => 'Seite 1', 'elements' => [
        ['id' => '1', 'type' => 'heading', 'key' => 'h1', 'text' => 'Überblick', 'level' => 1],
        ['id' => '2', 'type' => 'section', 'key' => 's', 'label' => 'Kunde', 'elements' => [
            ['id' => '3', 'type' => 'text', 'key' => 'name', 'label' => 'Ein sehr langer Feldname, der in der kompakten Ansicht umbrechen muss'],
            ['id' => '4', 'type' => 'heading', 'key' => 'h2', 'text' => 'Details', 'level' => 2],
        ]],
        ['id' => '5', 'type' => 'photo', 'key' => 'photos', 'label' => 'Fotos', 'multiple' => true],
        ['id' => '6', 'type' => 'signature', 'key' => 'sig', 'label' => 'Unterschrift Kunde'],
        ['id' => '7', 'type' => 'repeater', 'key' => 'rows', 'label' => 'Positionen', 'display' => 'table', 'elements' => [
            ['id' => '8', 'type' => 'text', 'key' => 'text', 'label' => 'Text'],
            ['id' => '9', 'type' => 'number', 'key' => 'qty', 'label' => 'Menge'],
            ['id' => '10', 'type' => 'photo', 'key' => 'pics', 'label' => 'Bilder', 'multiple' => true],
        ]],
    ]]];
}

function semData(): array
{
    return [
        'name' => 'Musterbau', 'photos' => [SEM_PHOTO, SEM_PHOTO_2],
        'sig' => ['attachment' => SEM_SIGNATURE, 'signerName' => 'Frau Weber', 'signedAt' => '2026-10-16T09:40:00+02:00'],
        'rows' => [['text' => 'Schalter', 'qty' => null, 'pics' => [SEM_PHOTO]], ['text' => '', 'qty' => '2', 'pics' => []]],
    ];
}

it('uses the full definition locale as the document language', function (): void {
    $html = semCompose(semInput(semSinglePage(), semData(), locale: 'de-AT'));

    expect($html->body)->toContain('<html lang="de-AT">')
        ->and($html->header)->toContain('<html lang="de-AT">')
        ->and($html->footer)->toContain('<html lang="de-AT">');
});

it('renders a heading outline without skipped levels on a single-page form', function (): void {
    $body = semCompose(semInput(semSinglePage(), semData()))->body;

    expect($body)->toContain('<h1>Bericht</h1>')
        ->toMatch('#<h2 class="[^"]*heading level-1[^"]*">Überblick</h2>#')
        ->toContain('<h2 class="section-title">Kunde</h2>')
        ->toMatch('#<h3 class="[^"]*heading level-2[^"]*">Details</h3>#')
        ->toContain('<h2 class="section-title">Positionen</h2>')
        ->not->toContain('<h3 class="section-title">Kunde</h3>');
});

it('nests section headings below page titles on multi-page forms', function (): void {
    $pages = [...semSinglePage(), ['key' => 'p2', 'title' => 'Seite 2', 'elements' => [['id' => '20', 'type' => 'text', 'key' => 'x', 'label' => 'X']]]];
    $body = semCompose(semInput($pages, [...semData(), 'x' => 'y']))->body;

    expect($body)->toContain('<h2 class="page-title">Seite 1</h2>')
        ->toContain('<h3 class="section-title">Kunde</h3>')
        ->toMatch('#<h3 class="[^"]*heading level-1[^"]*">Überblick</h3>#');
});

it('gives photos and signatures text alternatives and table headers a scope', function (): void {
    $body = semCompose(semInput(semSinglePage(), semData()))->body;

    expect($body)->toContain('alt="Fotos – Foto 1 von 2"')->toContain('alt="Fotos – Foto 2 von 2"')
        ->toContain('alt="Unterschrift von Frau Weber"')
        ->toContain('<th scope="col" class="index">')
        ->toMatch('#<th scope="col"[^>]*>Menge</th>#')
        ->not->toContain('alt=""');
});

it('keeps the watermark readable for assistive technology', function (): void {
    $body = semCompose(semInput(semSinglePage(), semData(), status: 'void'))->body;

    expect($body)->toContain('class="watermark"')->not->toContain('aria-hidden');
});

it('prints em dashes for empty table cells and photo thumbnails inside table repeaters', function (): void {
    $body = semCompose(semInput(semSinglePage(), semData()))->body;
    preg_match('#<table class="rows">.*?</table>#s', $body, $table);

    expect($table[0])->toContain('<td class="numeric">—</td>')
        ->toContain('<td class="">—</td>')
        ->toContain('class="thumbs"')
        ->toContain('alt="Bilder – Foto 1 von 1"')
        ->toMatch('#<img src="data:image/png;base64,[^"]+"#');
});

it('uses readable brand colors for text and fills', function (): void {
    $body = semCompose(semInput(semSinglePage(), semData(), primary: '#facc15'))->body;
    $branding = new Branding('#facc15', '#facc15', 'inter', null, 'Acme GmbH', []);

    expect($body)->toContain('--on-primary: #111827')
        ->toContain('--primary-text: '.$branding->primaryText())
        ->toContain('--accent-text: '.$branding->accentText())
        ->toContain('color: var(--on-primary)')
        ->and($branding->primaryText())->not->toBe('#facc15');
});

it('lets long labels wrap in the compact layout', function (): void {
    $body = semCompose(semInput(semSinglePage(), semData(), design: ['layout' => 'compact']))->body;

    expect($body)->toContain('class="layout-compact"')
        ->and($body)->not->toMatch('/\.layout-compact \.field \.label \{[^}]*white-space: nowrap/')
        ->and($body)->toMatch('/\.layout-compact \.field \.label \{[^}]*min-width: 0/')
        ->and($body)->toMatch('/\.layout-compact \.field \.value \{[^}]*min-width: 0/');
});

it('says how many photos could not be printed instead of dropping them silently', function (): void {
    $body = semCompose(semInput(semSinglePage(), [...semData(), 'photos' => [SEM_PHOTO, '0192f1c4-7a1e-7c3b-9a55-1d2e3f4a5bff']]))->body;

    expect($body)->toContain('1 Foto konnte nicht gedruckt werden')->toContain('alt="Fotos – Foto 1 von 1"');
});
