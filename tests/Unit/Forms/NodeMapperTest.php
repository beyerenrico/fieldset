<?php

declare(strict_types=1);

use App\Domain\Forms\Builder\KeyTypes;
use App\Domain\Forms\Builder\NodeMapper;
use App\Domain\Forms\Schema\DefinitionTools;
use FormSchema\Json\Json;
use Symfony\Component\Finder\Finder;

/* Builder v2 inspector (ADR 0037): one node (settings, page or element) ↔ v1 field state, losslessly. */

/** @return array<string, array{string}> every spec template plus the Builder v2 contract fixture */
function nodeMapperDefinitions(): array
{
    $definitions = [];
    foreach (Finder::create()->files()->in(dirname(__DIR__, 3).'/spec/templates')->name('*.json')->notName('index.json') as $file) {
        $definitions['template '.$file->getFilename()] = [(string) file_get_contents($file->getRealPath())];
    }
    $definitions['contract fixture'] = [(string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/builder-v2/contract/definition.json')];

    return $definitions;
}

/** @return list<array{string, stdClass}> [kind, node with children] for the definition and every page and element */
function nodesOf(stdClass $definition): array
{
    $nodes = [['settings', $definition]];
    foreach ($definition->pages as $page) {
        $nodes[] = ['page', $page];
    }
    foreach (DefinitionTools::elements($definition) as $element) {
        $nodes[] = ['element', $element];
    }

    return $nodes;
}

dataset('node mapper definitions', fn (): array => nodeMapperDefinitions());

it('round-trips every node of every template losslessly', function (string $json): void {
    $definition = Json::decode($json);
    $ids = array_map(fn (stdClass $e): string => $e->id, DefinitionTools::elements($definition));
    $mapper = new NodeMapper(KeyTypes::fromDefinition($definition), $ids);

    foreach (nodesOf($definition) as [$kind, $node]) {
        $own = NodeMapper::withoutChildren($kind, $node);
        $state = json_decode(json_encode($mapper->toState($kind, $own)), true); // as Livewire holds it
        $back = $mapper->fromState($kind, $state, is_string($node->type ?? null) ? $node->type : null);

        expect(Json::canonical($back))->toBe(Json::canonical($own), "{$kind} ".($node->key ?? ''));
    }
})->with('node mapper definitions');

it('strips children, so the state never carries nested nodes', function (): void {
    $definition = Json::decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/builder-v2/contract/definition.json'));
    $mapper = new NodeMapper;

    $settings = $mapper->toState('settings', $definition);
    $page = $mapper->toState('page', $definition->pages[0]);
    $columns = $mapper->toState('element', $definition->pages[0]->elements[1]->elements[0]);

    expect($settings)->not->toHaveKey('pages')->and(json_decode($settings['_source'], true))->not->toHaveKey('pages')
        ->and($page)->not->toHaveKey('elements')->and(json_decode($page['_source'], true))->not->toHaveKey('elements')
        ->and($columns)->not->toHaveKey('columns')->and(json_decode($columns['_source'], true))->not->toHaveKey('columns')
        ->and($definition->pages)->toHaveCount(2); // the input is not mutated
});

it('applies an edited label and keeps x- and unknown properties verbatim', function (): void {
    $definition = Json::decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/builder-v2/contract/definition.json'));
    $customer = $definition->pages[0]->elements[0];
    $mapper = new NodeMapper(KeyTypes::fromDefinition($definition));
    $state = $mapper->toState('element', $customer);
    $state['label'] = 'Kunde (Firma)';

    $node = $mapper->fromState('element', $state, 'text');

    expect(Json::canonical($node))->toBe(Json::canonical(Json::decode(
        '{"id":"0192f1c4-0000-7000-8000-00000000b011","type":"text","key":"customer","label":"Kunde (Firma)","required":true,"x-crm":{"field":"name"}}',
    )));
});

it('keeps the key of a published element', function (): void {
    $element = Json::decode('{"id":"0192f1c4-0000-7000-8000-00000000b011","type":"text","key":"customer","label":"Kunde"}');
    $mapper = new NodeMapper([], ['0192f1c4-0000-7000-8000-00000000b011']);
    $state = $mapper->toState('element', $element);
    expect($state['key_locked'])->toBeTrue();

    $state['key'] = 'renamed';

    expect($mapper->fromState('element', $state, 'text')->key)->toBe('customer');
});

it('compiles rules with the key types of the whole definition', function (): void {
    $element = Json::decode('{"id":"0192f1c4-0000-7000-8000-00000000b011","type":"text","key":"note","label":"Notiz"}');
    $mapper = new NodeMapper(['qty' => 'number', 'note' => 'text']);
    $state = $mapper->toState('element', $element);
    $state['visible_if'] = ['mode' => 'rules', 'match' => 'all', 'json' => '', 'groups' => [
        ['match' => 'all', 'rules' => [['field' => 'qty', 'operator' => 'gt', 'value' => '10']]],
    ]];

    expect(Json::encode($mapper->fromState('element', $state, 'text')->visibleIf))->toBe('{"dec.gt":[{"var":"qty"},"10"]}');
});

it('maps settings and pages, dropping numbering whose format was cleared', function (): void {
    $definition = Json::decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/builder-v2/contract/definition.json'));
    $mapper = new NodeMapper;
    $settings = $mapper->toState('settings', $definition);
    $settings['numbering_format'] = '';
    $settings['title'] = 'Neu';
    $page = $mapper->toState('page', $definition->pages[1]);
    $page['title'] = 'Artikel';

    $backSettings = $mapper->fromState('settings', $settings);
    $backPage = $mapper->fromState('page', $page);

    expect($backSettings->title)->toBe('Neu')
        ->and(property_exists($backSettings->settings, 'numbering'))->toBeFalse()
        ->and($backSettings->{'$schema'})->toBe($definition->{'$schema'})
        ->and(property_exists($backSettings, 'pages'))->toBeFalse()
        ->and($backPage->title)->toBe('Artikel')
        ->and($backPage->id)->toBe('0192f1c4-0000-7000-8000-00000000b002')
        ->and(property_exists($backPage, 'elements'))->toBeFalse();
});

it('rejects an unknown kind', function (): void {
    (new NodeMapper)->toState('column', new stdClass);
})->throws(InvalidArgumentException::class);
