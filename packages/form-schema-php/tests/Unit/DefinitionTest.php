<?php

declare(strict_types=1);

use FormSchema\Definition\Definition;
use FormSchema\Definition\Element;
use FormSchema\Definition\ElementType;
use FormSchema\Definition\InvalidDefinitionException;
use FormSchema\Json\Json;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function (): void {
    $this->json = fs_tree([
        'specVersion' => '1.0.0',
        'key' => 'demo',
        'title' => 'Demo',
        'locale' => 'de-DE',
        'settings' => ['layout' => 'wizard'],
        'pages' => [
            ['key' => 'one', 'title' => 'One', 'visibleIf' => true, 'elements' => [
                fs_element('heading', 'intro', ['text' => 'Hi']),
                fs_element('text', 'name', ['id' => 'aaaaaaaa-0000-4000-8000-00000000000a', 'label' => 'Name']),
                fs_element('section', 'details', ['elements' => [fs_element('number', 'qty', ['label' => 'Qty'])]]),
                fs_element('columns', 'cols', ['columns' => [
                    ['elements' => [fs_element('email', 'mail', ['label' => 'Mail'])]],
                    ['elements' => [fs_element('text', 'phone', ['label' => 'Phone'])]],
                ]]),
                fs_element('repeater', 'items', ['label' => 'Items', 'elements' => [
                    fs_element('text', 'name', ['label' => 'Row name']),
                    fs_element('number', 'qty', ['label' => 'Row qty']),
                    fs_element('repeater', 'parts', ['label' => 'Parts', 'elements' => [
                        fs_element('text', 'serial', ['label' => 'Serial']),
                        fs_element('calculated', 'part_total', ['label' => 'Part total', 'expression' => 1]),
                    ]]),
                ]]),
                fs_element('calculated', 'total', ['label' => 'Total', 'expression' => 2]),
            ]],
            ['key' => 'two', 'title' => 'Two', 'elements' => [fs_element('text', 'notes', ['label' => 'Notes'])]],
        ],
    ]);
    $this->definition = Definition::fromJson($this->json);
});

test('fromJson rejects values that are not JSON objects', function (mixed $value): void {
    expect(fn () => Definition::fromJson($value))
        ->toThrow(InvalidDefinitionException::class, 'A form definition must be a JSON object.');
})->with([
    'null' => [null],
    'string' => ['{"key":"x"}'],
    'integer' => [1],
    'list' => [[1, 2]],
    'empty array' => [[]],
]);

test('InvalidDefinitionException is an InvalidArgumentException', function (): void {
    expect(new InvalidDefinitionException('x'))->toBeInstanceOf(InvalidArgumentException::class);
});

test('fromJson returns a given Definition unchanged', function (): void {
    expect(Definition::fromJson($this->definition))->toBe($this->definition);
});

test('accessors read the root properties', function (): void {
    $definition = $this->definition;

    expect($definition->specVersion())->toBe('1.0.0')
        ->and($definition->key())->toBe('demo')
        ->and($definition->title())->toBe('Demo')
        ->and($definition->locale())->toBe('de-DE')
        ->and(Json::canonical($definition->settings()))->toBe('{"layout":"wizard"}');
});

test('accessors fall back for missing or mistyped properties', function (): void {
    $definition = Definition::fromJson(fs_tree(['specVersion' => 1, 'key' => null, 'settings' => 'x']));

    expect($definition->specVersion())->toBe('')
        ->and($definition->key())->toBe('')
        ->and($definition->title())->toBe('')
        ->and($definition->locale())->toBe('')
        ->and($definition->settings())->toEqual(new stdClass)
        ->and($definition->pages)->toBe([])
        ->and($definition->elements)->toBe([])
        ->and(array_keys($definition->scopes))->toBe(['']);
});

test('toJson returns an independent deep copy and the input stays untouched', function (): void {
    $before = Json::canonical($this->json);
    $copy = $this->definition->toJson();

    expect(Json::canonical($copy))->toBe($before)
        ->and($copy)->not->toBe($this->definition->raw);

    $copy->title = 'Changed';
    $copy->pages[0]->elements[1]->label = 'Changed';
    $this->json->key = 'mutated';
    $this->json->pages[0]->title = 'Mutated';

    expect($this->definition->title())->toBe('Demo')
        ->and($this->definition->key())->toBe('demo')
        ->and($this->definition->pages[0]->raw->title)->toBe('One')
        ->and($this->definition->toJson()->pages[0]->elements[1]->label)->toBe('Name');
});

test('elements are listed in pre-order with paths, scopes and parents', function (): void {
    $rows = array_map(
        static fn (Element $e): string => implode('|', [$e->path, $e->type, $e->key, $e->scopeId, $e->parentPath ?? '-', $e->pageIndex]),
        $this->definition->elements,
    );
    $items = '/pages/0/elements/4';
    $parts = $items.'/elements/2';

    expect($rows)->toBe([
        '/pages/0/elements/0|heading|intro||-|0',
        '/pages/0/elements/1|text|name||-|0',
        '/pages/0/elements/2|section|details||-|0',
        '/pages/0/elements/2/elements/0|number|qty||/pages/0/elements/2|0',
        '/pages/0/elements/3|columns|cols||-|0',
        '/pages/0/elements/3/columns/0/elements/0|email|mail||/pages/0/elements/3|0',
        '/pages/0/elements/3/columns/1/elements/0|text|phone||/pages/0/elements/3|0',
        $items.'|repeater|items||-|0',
        $items.'/elements/0|text|name|'.$items.'|'.$items.'|0',
        $items.'/elements/1|number|qty|'.$items.'|'.$items.'|0',
        $parts.'|repeater|parts|'.$items.'|'.$items.'|0',
        $parts.'/elements/0|text|serial|'.$parts.'|'.$parts.'|0',
        $parts.'/elements/1|calculated|part_total|'.$parts.'|'.$parts.'|0',
        '/pages/0/elements/5|calculated|total||-|0',
        '/pages/1/elements/0|text|notes||-|1',
    ]);
});

test('pages expose index, key, title, path and raw properties', function (): void {
    [$one, $two] = $this->definition->pages;

    expect([$one->index, $one->key, $one->title, $one->path])->toBe([0, 'one', 'One', '/pages/0'])
        ->and([$two->index, $two->key, $two->path])->toBe([1, 'two', '/pages/1'])
        ->and($one->has('visibleIf'))->toBeTrue()
        ->and($one->get('visibleIf'))->toBeTrue()
        ->and($two->has('visibleIf'))->toBeFalse()
        ->and($two->get('visibleIf', 'fallback'))->toBe('fallback')
        ->and(array_map(static fn (Element $e): string => $e->key, $one->elements))
        ->toBe(['intro', 'name', 'details', 'cols', 'items', 'total']);
});

test('parsing skips malformed pages, elements and columns but keeps indices', function (): void {
    $definition = Definition::fromJson(fs_tree(['pages' => [
        'not a page',
        ['key' => 5, 'title' => null, 'elements' => [
            null,
            ['type' => 7, 'key' => 'k', 'id' => 3],
            fs_element('columns', 'c', ['columns' => ['bad', ['elements' => 'bad'], ['elements' => [1, fs_element('text', 'x')]]]]),
            fs_element('columns', 'd', ['columns' => 'bad']),
            fs_element('section', 's', ['elements' => ['k' => 'object, not a list']]),
            fs_element('repeater', 'r'),
        ]],
        ['key' => 'no_elements', 'title' => 'T', 'elements' => 'bad'],
    ]]));

    $page = $definition->pages[0];
    $paths = array_map(static fn (Element $e): string => $e->path.'|'.$e->type.'|'.$e->id, $definition->elements);

    expect(count($definition->pages))->toBe(2)
        ->and([$page->index, $page->key, $page->title, $page->path])->toBe([1, '', '', '/pages/1'])
        ->and($definition->pages[1]->elements)->toBe([])
        ->and($paths[0])->toBe('/pages/1/elements/1||')
        ->and(array_slice($paths, 1, 2))->toBe([
            '/pages/1/elements/2|columns|'.$definition->elements[1]->id,
            '/pages/1/elements/2/columns/2/elements/1|text|'.$definition->elements[2]->id,
        ])
        ->and($definition->page(0))->toBeNull()
        ->and($definition->page(1))->toBe($page)
        ->and($definition->page(2)?->key)->toBe('no_elements')
        ->and($definition->page(3))->toBeNull()
        ->and(count($definition->elements))->toBe(6)
        ->and($definition->scope('/pages/1/elements/5')?->elements)->toBe([]);
});

test('a non-list pages value yields no pages', function (): void {
    $json = fs_definition([fs_element('text', 'a')]);
    $json->pages = ['first' => $json->pages[0]];

    expect(Definition::fromJson($json)->pages)->toBe([]);
});

test('scopes hold the data elements of the root and of every repeater row', function (): void {
    $definition = $this->definition;
    $items = '/pages/0/elements/4';
    $parts = $items.'/elements/2';
    $root = $definition->rootScope();

    expect(array_keys($definition->scopes))->toBe(['', $items, $parts])
        ->and([$root->id, $root->repeater, $root->parentId, $root->isRoot()])->toBe(['', null, null, true])
        ->and(array_keys($root->elements))->toBe(['name', 'qty', 'mail', 'phone', 'items', 'total', 'notes'])
        ->and($root->element('intro'))->toBeNull()
        ->and($root->element('qty')?->path)->toBe('/pages/0/elements/2/elements/0');

    $row = $definition->scope($items);
    expect($row?->isRoot())->toBeFalse()
        ->and($row?->repeater?->key)->toBe('items')
        ->and($row?->parentId)->toBe('')
        ->and(array_keys($row->elements))->toBe(['name', 'qty', 'parts'])
        ->and($definition->scope($parts)?->parentId)->toBe($items)
        ->and(array_keys($definition->scope($parts)->elements))->toBe(['serial', 'part_total'])
        ->and($definition->scope('/nope'))->toBeNull();
});

test('scope maps keep the first element of a duplicated key and skip empty keys', function (): void {
    $definition = Definition::fromJson(fs_definition([
        fs_element('text', 'a', ['label' => 'first']),
        fs_element('number', 'a', ['label' => 'second']),
        fs_element('text', ''),
    ]));

    expect(array_keys($definition->rootScope()->elements))->toBe(['a'])
        ->and($definition->rootScope()->element('a')?->label())->toBe('first');
});

test('scopeChain walks outwards to the root', function (): void {
    $items = '/pages/0/elements/4';
    $parts = $items.'/elements/2';
    $ids = static fn (array $chain): array => array_map(static fn ($scope): string => $scope->id, $chain);

    expect($ids($this->definition->scopeChain($parts)))->toBe([$parts, $items, ''])
        ->and($ids($this->definition->scopeChain($items)))->toBe([$items, ''])
        ->and($ids($this->definition->scopeChain('')))->toBe([''])
        ->and($this->definition->scopeChain('/unknown'))->toBe([]);
});

test('resolveKey prefers the innermost scope', function (string $key, string $scope, ?string $path): void {
    expect($this->definition->resolveKey($key, $scope)?->path)->toBe($path);
})->with([
    'row key shadows root key' => ['name', '/pages/0/elements/4', '/pages/0/elements/4/elements/0'],
    'root key from a row' => ['total', '/pages/0/elements/4', '/pages/0/elements/5'],
    'root key from a nested row' => ['notes', '/pages/0/elements/4/elements/2', '/pages/1/elements/0'],
    'outer row key from a nested row' => ['qty', '/pages/0/elements/4/elements/2', '/pages/0/elements/4/elements/1'],
    'root key at root' => ['name', '', '/pages/0/elements/1'],
    'row key is invisible at root' => ['serial', '', null],
    'layout key is not data' => ['intro', '', null],
    'unknown scope' => ['name', '/nope', null],
]);

test('repeaterChain lists enclosing repeaters outermost first', function (): void {
    $definition = $this->definition;
    $keys = static fn (array $list): array => array_map(static fn (Element $e): string => $e->key, $list);

    expect($keys($definition->repeaterChain($definition->elementByPath('/pages/0/elements/4/elements/2/elements/0'))))
        ->toBe(['items', 'parts'])
        ->and($keys($definition->repeaterChain($definition->elementByPath('/pages/0/elements/4/elements/2'))))->toBe(['items'])
        ->and($definition->repeaterChain($definition->elementByPath('/pages/0/elements/4')))->toBe([]);
});

test('elements can be found by path and by case-insensitive id', function (): void {
    $definition = $this->definition;

    expect($definition->elementByPath('/pages/1/elements/0')?->key)->toBe('notes')
        ->and($definition->elementByPath('/pages/9'))->toBeNull()
        ->and($definition->elementById('AAAAAAAA-0000-4000-8000-00000000000A')?->key)->toBe('name')
        ->and($definition->elementById('aaaaaaaa-0000-4000-8000-00000000000a')?->key)->toBe('name')
        ->and($definition->elementById('bbbbbbbb-0000-4000-8000-00000000000b'))->toBeNull()
        ->and(Definition::fromJson(fs_definition([['type' => 'text', 'key' => 'a']]))->elementById(''))->toBeNull();
});

test('calculatedElements, descendants and position', function (): void {
    $definition = $this->definition;
    $keys = static fn (array $list): array => array_map(static fn (Element $e): string => $e->key, $list);

    expect($keys($definition->calculatedElements()))->toBe(['part_total', 'total'])
        ->and($keys($definition->descendants($definition->elementByPath('/pages/0/elements/4'))))
        ->toBe(['name', 'qty', 'parts', 'serial', 'part_total'])
        ->and($keys($definition->descendants($definition->elementByPath('/pages/0/elements/3'))))->toBe(['mail', 'phone'])
        ->and($definition->descendants($definition->elementByPath('/pages/0/elements/1')))->toBe([])
        ->and($definition->position('/pages/0/elements/0'))->toBe(0)
        ->and($definition->position('/pages/1/elements/0'))->toBe(14)
        ->and($definition->position('/pages/7'))->toBe(PHP_INT_MAX);
});

test('Element::int reads integers and integral floats only', function (mixed $value, int $expected): void {
    $element = Definition::fromJson(fs_definition([fs_element('rating', 'r', ['max' => $value])]))->elements[0];

    expect($element->int('max', 5))->toBe($expected);
})->with([
    'integer' => [7, 7],
    'integral float' => [8.0, 8],
    'negative integral float' => [-3.0, -3],
    'fractional float' => [2.5, 5],
    'huge float' => [1.0e20, 5],
    'largest safe integer' => [9007199254740992, 9007199254740992],
    '2^53 + 1 rounds like a double' => [9007199254740993, 9007199254740992],
    'integer far beyond 2^53' => [1152921504606846976, 5],
    'string' => ['7', 5],
    'null' => [null, 5],
]);

test('Element accessors for properties, label, validation and options', function (): void {
    $element = Definition::fromJson(fs_definition([fs_element('select', 's', [
        'label' => 'Pick',
        'validation' => ['pattern' => 'x'],
        'options' => [['value' => 'a'], 'bad', ['label' => 'no value'], ['value' => 5], ['value' => 'b']],
    ])]))->elements[0];
    $bare = Definition::fromJson(fs_definition([fs_element('text', 't', ['label' => 3, 'validation' => 'x', 'options' => 'x'])]))->elements[0];

    expect($element->has('label'))->toBeTrue()
        ->and($element->get('label'))->toBe('Pick')
        ->and($element->get('missing', 'dflt'))->toBe('dflt')
        ->and($element->label())->toBe('Pick')
        ->and($element->optionValues())->toBe(['a', 'b'])
        ->and($element->validation()->pattern)->toBe('x')
        ->and($element->rowScopeId())->toBe($element->path)
        ->and($bare->label())->toBeNull()
        ->and($bare->validation())->toEqual(new stdClass)
        ->and($bare->optionValues())->toBe([])
        ->and($bare->has('missing'))->toBeFalse();
});

test('Element type predicates, format, isDecimal and scale', function (array $properties, string $type, array $expected): void {
    $element = Definition::fromJson(fs_definition([fs_element($type, 'e', $properties)]))->elements[0];

    expect([$element->isLayout(), $element->isData(), $element->isRepeater(), $element->isCalculated(), $element->format(), $element->isDecimal(), $element->scale()])
        ->toBe($expected);
})->with([
    'text' => [[], 'text', [false, true, false, false, 'number', false, 6]],
    'number' => [[], 'number', [false, true, false, false, 'number', true, 6]],
    'money default scale' => [[], 'money', [false, true, false, false, 'number', true, 2]],
    'money explicit scale' => [['scale' => 3], 'money', [false, true, false, false, 'number', true, 3]],
    'money negative scale' => [['scale' => -1], 'money', [false, true, false, false, 'number', true, 2]],
    'money scale 0' => [['scale' => 0], 'money', [false, true, false, false, 'number', true, 0]],
    'money scale 4' => [['scale' => 4], 'money', [false, true, false, false, 'number', true, 4]],
    'money scale above 4' => [['scale' => 5], 'money', [false, true, false, false, 'number', true, 2]],
    'calculated scale 10' => [['scale' => 10], 'calculated', [false, true, false, true, 'number', true, 10]],
    'calculated scale above 10' => [['scale' => 11], 'calculated', [false, true, false, true, 'number', true, 6]],
    'calculated money scale above 10' => [['format' => 'money', 'scale' => 11], 'calculated', [false, true, false, true, 'money', true, 2]],
    'calculated default' => [[], 'calculated', [false, true, false, true, 'number', true, 6]],
    'calculated money' => [['format' => 'money'], 'calculated', [false, true, false, true, 'money', true, 2]],
    'calculated text' => [['format' => 'text', 'scale' => 4.0], 'calculated', [false, true, false, true, 'text', false, 4]],
    'calculated unknown format' => [['format' => 'percent', 'scale' => -2], 'calculated', [false, true, false, true, 'number', true, 6]],
    'calculated non-string format' => [['format' => 1], 'calculated', [false, true, false, true, 'number', true, 6]],
    'repeater' => [[], 'repeater', [false, true, true, false, 'number', false, 6]],
    'section' => [[], 'section', [true, false, false, false, 'number', false, 6]],
    'reserved' => [[], 'record', [false, true, false, false, 'number', false, 6]],
]);

test('ElementType classifies type names', function (string $type, bool $layout, bool $data, bool $reserved, bool $known): void {
    expect([ElementType::isLayout($type), ElementType::isData($type), ElementType::isReserved($type), ElementType::isKnown($type)])
        ->toBe([$layout, $data, $reserved, $known]);
})->with([
    'section' => ['section', true, false, false, true],
    'page_break' => ['page_break', true, false, false, true],
    'text' => ['text', false, true, false, true],
    'repeater' => ['repeater', false, true, false, true],
    'hidden' => ['hidden', false, true, false, true],
    'record' => ['record', false, true, true, false],
    'lookup' => ['lookup', false, true, true, false],
    'unknown' => ['widget', false, true, false, false],
    'empty' => ['', false, false, false, false],
]);
