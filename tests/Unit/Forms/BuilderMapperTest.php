<?php

declare(strict_types=1);

use App\Domain\Forms\Builder\BuilderMapper;
use App\Domain\Forms\Schema\DefinitionTools;
use FormSchema\Json\Json;
use Symfony\Component\Finder\Finder;

/** Every definition shipped with the spec: templates plus all conformance definitions. */
function fixtureDefinitions(): array
{
    $root = dirname(__DIR__, 3).'/spec';
    $definitions = [];
    foreach (Finder::create()->files()->in($root.'/templates')->name('*.json')->notName('index.json') as $file) {
        $definitions['template '.$file->getFilename()] = [(string) file_get_contents($file->getRealPath())];
    }
    foreach (Finder::create()->files()->in($root.'/conformance')->name('*.json') as $file) {
        $suite = json_decode((string) file_get_contents($file->getRealPath()), true);
        if (isset($suite['definition'])) {
            $definitions[$file->getRelativePathname()] = [json_encode($suite['definition'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        }
        foreach ($suite['tests'] ?? [] as $i => $case) {
            if (isset($case['definition']) && is_array($case['definition']) && isset($case['definition']['pages'])) {
                $definitions[$file->getRelativePathname().' #'.$i] = [json_encode($case['definition'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
            }
        }
    }

    return $definitions;
}

dataset('fixture definitions', fn (): array => fixtureDefinitions());

it('round-trips every fixture definition losslessly', function (string $json): void {
    $definition = Json::decode($json);
    $mapper = new BuilderMapper;

    $state = json_decode(json_encode($mapper->toState($definition)), true); // as Livewire would hold it
    $back = $mapper->toDefinition($state);

    expect(Json::canonical($back))->toBe(Json::canonical($definition));
})->with('fixture definitions');

function sampleDefinition(): stdClass
{
    return Json::decode(json_encode([
        'specVersion' => '1.0.0', 'key' => 'demo', 'title' => 'Demo', 'locale' => 'de-DE', 'x-vendor' => ['empty' => (object) []],
        'pages' => [[
            'id' => '00000000-0000-4000-8000-000000000001', 'key' => 'p', 'title' => 'P',
            'elements' => [
                ['id' => '00000000-0000-4000-8000-000000000002', 'type' => 'number', 'key' => 'qty', 'label' => 'Menge',
                    'required' => false, 'validation' => ['min' => 0, 'step' => '0.25'], 'x-import' => ['confidence' => 0.4]],
                ['id' => '00000000-0000-4000-8000-000000000003', 'type' => 'text', 'key' => 'note', 'label' => 'Notiz',
                    'visibleIf' => ['dec.gt' => [['var' => 'qty'], '10']]],
            ],
        ]],
    ]));
}

it('applies an edited label and keeps everything else verbatim', function (): void {
    $mapper = new BuilderMapper;
    $state = $mapper->toState(sampleDefinition());
    $state['pages'][0]['elements'][0]['data']['label'] = 'Anzahl';

    $back = $mapper->toDefinition($state);
    $expected = sampleDefinition();
    $expected->pages[0]->elements[0]->label = 'Anzahl';

    expect(Json::canonical($back))->toBe(Json::canonical($expected))
        ->and($back->pages[0]->elements[0]->required)->toBeFalse()   // explicit false survives
        ->and($back->pages[0]->elements[0]->validation->min)->toBe(0) // JSON number survives
        ->and(Json::encode($back->{'x-vendor'}))->toBe('{"empty":{}}');
});

it('compiles visual rules on number fields to decimal operators', function (): void {
    $mapper = new BuilderMapper;
    $state = $mapper->toState(sampleDefinition());
    expect($state['pages'][0]['elements'][1]['data']['visible_if'])->toMatchArray(['mode' => 'rules', 'match' => 'all']);

    $state['pages'][0]['elements'][1]['data']['visible_if'] = ['mode' => 'rules', 'match' => 'all', 'groups' => [
        ['match' => 'any', 'rules' => [
            ['field' => 'qty', 'operator' => 'gte', 'value' => '5'],
            ['field' => 'qty', 'operator' => 'empty', 'value' => null],
        ]],
    ], 'json' => ''];
    $back = $mapper->toDefinition($state);

    expect(Json::encode($back->pages[0]->elements[1]->visibleIf))
        ->toBe('{"or":[{"dec.gte":[{"var":"qty"},"5"]},{"!":{"var":"qty"}}]}');
});

it('gives cloned blocks a new id and a suffixed key, and new blocks an id', function (): void {
    $mapper = new BuilderMapper;
    $state = $mapper->toState(sampleDefinition());
    $state['pages'][0]['elements'][] = $state['pages'][0]['elements'][1];                       // Filament clone
    $state['pages'][0]['elements'][] = ['type' => 'email', 'data' => ['key' => 'mail', 'label' => 'Mail']]; // new block

    $elements = $mapper->toDefinition($state)->pages[0]->elements;

    expect($elements[2]->key)->toBe('note_copy')
        ->and($elements[2]->id)->not->toBe($elements[1]->id)
        ->and($elements[3]->id)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($elements[3]->type)->toBe('email');
});

it('keeps locked keys even if the submitted state changes them', function (): void {
    $mapper = new BuilderMapper(['00000000-0000-4000-8000-000000000002']);
    $state = $mapper->toState(sampleDefinition());
    expect($state['pages'][0]['elements'][0]['data']['key_locked'])->toBeTrue();

    $state['pages'][0]['elements'][0]['data']['key'] = 'renamed';

    expect($mapper->toDefinition($state)->pages[0]->elements[0]->key)->toBe('qty');
});

it('maps nested repeaters, sections and columns', function (): void {
    $definition = DefinitionTools::copy(Json::decode((string) file_get_contents(base_path('spec/templates/inspection_checklist.json'))));
    $state = (new BuilderMapper)->toState($definition);

    $section = $state['pages'][1]['elements'][0];
    expect($section['type'])->toBe('section')
        ->and($section['data']['elements'])->not->toBeEmpty()
        ->and($section['data']['elements'][0]['data']['options'][0]['value'])->toBe('ok');
});

it('stores typed defaults for boolean and integer elements', function (): void {
    $definition = sampleDefinition();
    $definition->pages[0]->elements[] = (object) ['id' => '00000000-0000-4000-8000-000000000010', 'type' => 'checkbox', 'key' => 'ok', 'label' => 'OK'];
    $definition->pages[0]->elements[] = (object) ['id' => '00000000-0000-4000-8000-000000000011', 'type' => 'rating', 'key' => 'stars', 'label' => 'Stars'];
    $mapper = new BuilderMapper;
    $state = $mapper->toState($definition);
    $state['pages'][0]['elements'][2]['data']['default'] = 'true';
    $state['pages'][0]['elements'][3]['data']['default'] = '4';

    $elements = $mapper->toDefinition($state)->pages[0]->elements;

    expect($elements[2]->default)->toBeTrue()->and($elements[3]->default)->toBe(4)
        ->and($elements[0]->default ?? null)->toBeNull();
});

it('removes numbering when its format is cleared', function (): void {
    $definition = Json::decode((string) file_get_contents(base_path('spec/templates/invoice.json')));
    $mapper = new BuilderMapper;
    $state = $mapper->toState($definition);
    $state['numbering_format'] = '';

    $back = $mapper->toDefinition($state);

    expect(property_exists($back->settings, 'numbering'))->toBeFalse()
        ->and($back->settings->layout)->toBe('wizard');
});
