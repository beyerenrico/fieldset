<?php

declare(strict_types=1);

use Fieldset\Definition\Definition;
use Fieldset\Definition\Element;
use Fieldset\Evaluation\CalculatedValue;
use Fieldset\Evaluation\DataTree;
use Fieldset\Evaluation\EvaluationState;
use Fieldset\Evaluation\Instance;
use Fieldset\Evaluation\InstanceWalker;
use Fieldset\Evaluation\LogicView;
use Fieldset\Evaluation\NormalizedData;
use Fieldset\Evaluation\Normalizer;
use Fieldset\Evaluation\ValidationError;
use Fieldset\Json\Json;
use Fieldset\Logic\JsonLogic;
use Fieldset\Logic\LogicEvaluator;

require_once __DIR__.'/../Support/helpers.php';

/*
 * Internal building blocks of the evaluator: data tree helpers, instances, logic view,
 * normalization, calculated values and the evaluation state.
 */

$definition = Definition::fromJson(fs_definition([
    fs_element('text', 'title', ['label' => 'Title']),
    fs_element('section', 'box', ['elements' => [fs_element('money', 'price', ['label' => 'Price', 'currency' => 'EUR'])]]),
    fs_element('repeater', 'items', ['label' => 'Items', 'elements' => [
        fs_element('text', 'name', ['label' => 'Name']),
        fs_element('calculated', 'line', ['label' => 'Line', 'expression' => 1]),
        fs_element('repeater', 'parts', ['label' => 'Parts', 'elements' => [fs_element('number', 'qty', ['label' => 'Qty'])]]),
    ]]),
    fs_element('calculated', 'total', ['label' => 'Total', 'expression' => 1]),
    fs_element('hidden', 'ref', ['label' => 'Ref']),
    fs_element('heading', 'head', ['text' => 'H']),
]));

$byKey = static fn (string $key): Element => array_values(array_filter($definition->elements, static fn (Element $e): bool => $e->key === $key))[0];

$values = ['title' => 'T', 'items' => [['name' => 'a', 'parts' => [['qty' => '1']]], 'junk', ['name' => 'b', 'line' => null]]];

$single = static fn (string $type, array $properties = []): Element => Definition::fromJson(fs_definition([fs_element($type, 'field', ['label' => 'F', ...$properties])]))->elements[0];

test('DataTree::row resolves row paths', function (array $rowPath, ?array $expected) use ($values): void {
    expect(DataTree::row($values, $rowPath))->toBe($expected);
})->with([
    'root' => [[], $values],
    'first row' => [[['items', 0]], $values['items'][0]],
    'nested row' => [[['items', 0], ['parts', 0]], ['qty' => '1']],
    'non-array row' => [[['items', 1]], null],
    'missing row' => [[['items', 9]], null],
    'non-array rows' => [[['title', 0]], null],
    'missing repeater' => [[['missing', 0]], null],
    'below a missing row' => [[['items', 1], ['parts', 0]], null],
]);

test('DataTree::has and get', function () use ($values): void {
    expect(DataTree::has($values, [], 'title'))->toBeTrue()
        ->and(DataTree::has($values, [['items', 2]], 'line'))->toBeTrue()
        ->and(DataTree::has($values, [['items', 2]], 'parts'))->toBeFalse()
        ->and(DataTree::has($values, [['items', 1]], 'name'))->toBeFalse()
        ->and(DataTree::get($values, [['items', 0], ['parts', 0]], 'qty'))->toBe('1')
        ->and(DataTree::get($values, [['items', 2]], 'line'))->toBeNull()
        ->and(DataTree::get($values, [['items', 9]], 'name'))->toBeNull()
        ->and(DataTree::get($values, [], 'missing'))->toBeNull();
});

test('DataTree::set and remove return changed copies', function () use ($values): void {
    $original = $values;
    $set = DataTree::set($values, [['items', 0], ['parts', 0]], 'qty', '2');
    $removed = DataTree::remove($values, [['items', 2]], 'line');

    expect($set['items'][0]['parts'][0])->toBe(['qty' => '2'])
        ->and($set['items'][2])->toBe($values['items'][2])
        ->and(DataTree::set($values, [], 'title', 'U')['title'])->toBe('U')
        ->and($removed['items'][2])->toBe(['name' => 'b'])
        ->and(DataTree::remove($values, [], 'title'))->not->toHaveKey('title')
        ->and($values)->toBe($original);
});

test('DataTree::set and remove leave the tree unchanged for unknown rows', function (array $rowPath) use ($values): void {
    expect(DataTree::set($values, $rowPath, 'name', 'x'))->toBe($values)
        ->and(DataTree::remove($values, $rowPath, 'name'))->toBe($values);
})->with([
    'non-array row' => [[['items', 1]]],
    'missing row' => [[['items', 9]]],
    'non-array rows' => [[['title', 0]]],
    'missing repeater' => [[['missing', 0]]],
    'nested below a missing row' => [[['items', 1], ['parts', 0]]],
]);

test('DataTree::toJson converts scope arrays and rows into JSON objects', function (array $data, string $expected) use ($definition): void {
    expect(Json::canonical(DataTree::toJson($definition, $definition->rootScope(), $data)))->toBe($expected);
})->with([
    'rows recursively, non-array rows kept' => [$values, '{"items":[{"name":"a","parts":[{"qty":"1"}]},"junk",{"line":null,"name":"b"}],"title":"T"}'],
    'row keys are re-indexed' => [['items' => [3 => ['name' => 'x']]], '{"items":[{"name":"x"}]}'],
    'null repeater' => [['items' => null], '{"items":null}'],
    'non-array repeater' => [['items' => 'x'], '{"items":"x"}'],
    'arrays of other keys stay arrays' => [['title' => ['a'], 'unknown' => [1, 2]], '{"title":["a"],"unknown":[1,2]}'],
]);

test('Instance::pointerFor builds scope pointers', function (array $rowPath, string $key, string $expected): void {
    expect(Instance::pointerFor($rowPath, $key))->toBe($expected);
})->with([
    'root' => [[], 'a', '/a'],
    'row' => [[['items', 2]], 'qty', '/items/2/qty'],
    'nested row' => [[['items', 0], ['parts', 1]], 'qty', '/items/0/parts/1/qty'],
    'escaped key' => [[], 'a/b~c', '/a~1b~0c'],
]);

test('InstanceWalker::walk lists instances in document order, rows in index order', function () use ($definition): void {
    $instances = InstanceWalker::walk($definition, ['items' => [['parts' => [[]]], []]]);
    $byPointer = array_column(array_map(static fn (Instance $i): array => [$i->pointer, $i], $instances), 1, 0);

    expect(array_keys($byPointer))->toBe([
        '/title', '/box', '/price', '/items', '/items/0/name', '/items/0/line', '/items/0/parts', '/items/0/parts/0/qty',
        '/items/1/name', '/items/1/line', '/items/1/parts', '/total', '/ref', '/head',
    ])
        ->and($byPointer['/title']->parentPointer)->toBeNull()
        ->and($byPointer['/price']->parentPointer)->toBe('/box')
        ->and($byPointer['/items/1/name']->parentPointer)->toBe('/items')
        ->and($byPointer['/items/1/name']->rowPath)->toBe([['items', 1]])
        ->and($byPointer['/items/0/parts/0/qty']->parentPointer)->toBe('/items/0/parts')
        ->and($byPointer['/items/0/parts/0/qty']->rowPath)->toBe([['items', 0], ['parts', 0]])
        ->and($byPointer['/items/0/parts/0/qty']->element->key)->toBe('qty')
        ->and($byPointer['/head']->pageIndex)->toBe(0);
});

test('InstanceWalker::walk skips rows of non-array repeaters and tracks pages', function () use ($definition): void {
    $pointers = array_map(static fn (Instance $i): string => $i->pointer, InstanceWalker::walk($definition, ['items' => 'x']));
    $paged = Definition::fromJson(fs_definition([], ['pages' => [
        ['key' => 'a', 'title' => 'A', 'elements' => [fs_element('text', 'x', ['label' => 'X'])]],
        ['key' => 'b', 'title' => 'B', 'elements' => [fs_element('text', 'y', ['label' => 'Y'])]],
    ]]));

    expect($pointers)->toBe(['/title', '/box', '/price', '/items', '/total', '/ref', '/head'])
        ->and(array_map(static fn (Instance $i): int => $i->pageIndex, InstanceWalker::walk($paged, [])))->toBe([0, 1]);
});

test('InstanceWalker::rowPaths enumerates the rows of a repeater chain', function (array $chain, array $data, array $expected) use ($byKey): void {
    expect(InstanceWalker::rowPaths(array_map($byKey, $chain), $data))->toBe($expected);
})->with([
    'root scope' => [[], [], [[]]],
    'rows' => [['items'], ['items' => [[], []]], [[['items', 0]], [['items', 1]]]],
    'nested rows' => [['items', 'parts'], ['items' => [['parts' => [[]]], [], ['parts' => [[], []]]]], [
        [['items', 0], ['parts', 0]], [['items', 2], ['parts', 0]], [['items', 2], ['parts', 1]],
    ]],
    'missing repeater' => [['items'], [], []],
    'non-array repeater' => [['items'], ['items' => 'x'], []],
]);

test('LogicView::build exposes every defined key; invisible and non-empty invalid values are null', function (array $visibility, string $expected) use ($definition): void {
    $data = [
        'title' => 5, 'price' => '1.50', 'ref' => ['bad'],
        'items' => [['name' => 'a', 'parts' => 'bad'], 'junk', ['name' => '', 'parts' => [['qty' => []], ['qty' => '']]]],
    ];

    expect(Json::canonical(LogicView::build($definition, $data, $visibility)))->toBe($expected);
})->with([
    'all visible' => [[], '{"items":[{"line":null,"name":"a","parts":null},null,{"line":null,"name":"","parts":[{"qty":[]},{"qty":""}]}],"price":"1.50","ref":null,"title":null,"total":null}'],
    'invisible elements' => [['/price' => false, '/items/0/name' => false, '/items/2/parts' => false], '{"items":[{"line":null,"name":null,"parts":null},null,{"line":null,"name":"","parts":null}],"price":null,"ref":null,"title":null,"total":null}'],
    'invisible repeater' => [['/items' => false], '{"items":null,"price":"1.50","ref":null,"title":null,"total":null}'],
]);

test('LogicView::scope layers built-ins, $root, root keys, row keys and $row', function (): void {
    $definition = Definition::fromJson(fs_definition([
        fs_element('text', 'name', ['label' => 'Name']),
        fs_element('repeater', 'rows', ['label' => 'Rows', 'elements' => [
            fs_element('text', 'name', ['label' => 'Row name']),
            fs_element('repeater', 'subs', ['label' => 'Subs', 'elements' => [fs_element('text', 'qty', ['label' => 'Qty'])]]),
        ]]),
    ]));
    $view = LogicView::build($definition, ['name' => 'root', 'rows' => [['name' => 'row0', 'subs' => [['qty' => 'q']]]]], []);

    $root = LogicView::scope($view, [], fs_context());
    $row = LogicView::scope($view, [['rows', 0]], fs_context());
    $sub = LogicView::scope($view, [['rows', 0], ['subs', 0]], fs_context());
    $missing = LogicView::scope($view, [['rows', 5]], fs_context());
    $notRows = LogicView::scope($view, [['name', 0]], fs_context());

    expect($root->name)->toBe('root')
        ->and($root->{'$root'})->toBe($view)
        ->and($root->{'$today'})->toBe('2026-10-16')
        ->and($root->{'$user'}->name)->toBe('Willi Worker')
        ->and(property_exists($root, '$row'))->toBeFalse()
        ->and($row->name)->toBe('row0')
        ->and($row->{'$row'}->name)->toBe('row0')
        ->and($row->{'$root'}->name)->toBe('root')
        ->and($sub->qty)->toBe('q')
        ->and($sub->name)->toBe('row0')
        ->and(Json::canonical($sub->{'$row'}))->toBe('{"qty":"q"}')
        ->and($missing->name)->toBe('root')
        ->and(property_exists($missing, '$row'))->toBeTrue()
        ->and($notRows->name)->toBe('root');
});

test('Normalizer::normalize treats non-object input as {}', function (mixed $input) use ($definition): void {
    expect(Normalizer::normalize($definition, $input))->toEqual(new NormalizedData(['total' => null], []));
})->with([
    'null' => [null],
    'string' => ['x'],
    'list' => [[new stdClass]],
]);

test('Normalizer::normalize drops unknown keys, cleans rows and reports repeater shapes', function () use ($definition): void {
    $normalized = Normalizer::normalize($definition, fs_tree([
        'title' => 'T', 'box' => 'layout', 'head' => 'x', 'unknown' => 1, 'total' => '99', 'price' => '12.5', 'ref' => ['a'],
        'items' => ['junk', ['name' => 'a', 'line' => '5', 'zzz' => 1, 'parts' => [['qty' => '2', 'q' => 1], 7]], ['name' => 'b', 'parts' => 'bad']],
    ]));

    expect($normalized->values)->toBe([
        'title' => 'T',
        'price' => '12.50',
        'ref' => ['a'],
        'items' => [['name' => 'a', 'parts' => [['qty' => '2']], 'line' => null], ['name' => 'b', 'parts' => [], 'line' => null]],
        'total' => null,
    ])->and($normalized->errors)->toEqual([
        new ValidationError('/items/0', 'type_mismatch'),
        new ValidationError('/items/0/parts/1', 'type_mismatch'),
        new ValidationError('/items/1/parts', 'type_mismatch'),
    ]);
});

test('Normalizer::normalize repeater values', function (mixed $value, ?array $rows, array $errors) use ($definition): void {
    $normalized = Normalizer::normalize($definition, (object) ['items' => $value]);

    expect($normalized->values['items'])->toBe($rows)
        ->and(fs_error_set($normalized->errors))->toBe($errors);
})->with([
    'null stays null' => [null, null, []],
    'empty list' => [[], [], []],
    'string' => ['rows', [], ['/items|type_mismatch']],
    'object' => [fs_tree(['a' => 1]), [], ['/items|type_mismatch']],
    'non-list array' => [[1 => new stdClass], [], ['/items|type_mismatch']],
    'rows with a null row' => [[null, new stdClass], [['line' => null]], ['/items/0|type_mismatch']],
]);

test('Normalizer rewrites valid money values to exactly scale digits', function (array $properties, mixed $value, mixed $expected): void {
    $definition = Definition::fromJson(fs_definition([fs_element('money', 'm', ['label' => 'M', 'currency' => 'EUR', ...$properties])]));

    expect(Normalizer::normalize($definition, (object) ['m' => $value])->values['m'])->toBe($expected);
})->with([
    'pads to scale 2' => [[], '12.5', '12.50'],
    'negative zero' => [[], '-0.0', '0.00'],
    'scale 0' => [['scale' => 0], '7', '7'],
    'scale 4' => [['scale' => 4], '1.5', '1.5000'],
    'scale above 4 falls back to 2' => [['scale' => 5], '1.5', '1.50'],
    'scale exceeded stays' => [[], '12.555', '12.555'],
    'invalid string stays' => [[], 'abc', 'abc'],
    'JSON number stays' => [[], 12, 12],
]);

test('Normalizer::defaultValue', function (Element|string $element, mixed $value, mixed $expected) use ($definition, $byKey, $single): void {
    $target = is_string($element) ? ($element === 'items' ? $byKey('items') : $single($element, $element === 'money' ? ['currency' => 'EUR'] : [])) : $element;

    expect(Normalizer::defaultValue($definition, $target, $value))->toBe($expected);
})->with([
    'repeater rows are cleaned silently' => ['items', [fs_tree(['name' => 'x', 'junk' => 1]), 5], [['name' => 'x', 'line' => null]]],
    'repeater empty list' => ['items', [], []],
    'repeater non-list array' => ['items', [1 => new stdClass], null],
    'repeater string' => ['items', 'rows', null],
    'repeater null' => ['items', null, null],
    'repeater of another definition' => [new Element(fs_tree(['type' => 'repeater']), '', 'repeater', 'x', '/nowhere', '', 0, null, []), [new stdClass], []],
    'money is normalized' => ['money', '5', '5.00'],
    'money JSON number as-is' => ['money', 5, 5],
    'hidden used as-is' => ['hidden', 1.5, 1.5],
    'number used as-is' => ['number', 5, 5],
    'null' => ['text', null, null],
]);

test('CalculatedValue::convert', function (array $properties, mixed $result, ?string $expected) use ($single): void {
    expect(CalculatedValue::convert($single('calculated', ['expression' => 1, ...$properties]), $result))->toBe($expected);
})->with([
    'text null' => [['format' => 'text'], null, null],
    'text string' => [['format' => 'text'], 'abc', 'abc'],
    'text empty string' => [['format' => 'text'], '', ''],
    'text true' => [['format' => 'text'], true, 'true'],
    'text false' => [['format' => 'text'], false, 'false'],
    'text integer' => [['format' => 'text'], 3, '3'],
    'text float' => [['format' => 'text'], 1.5, '1.5'],
    'text list' => [['format' => 'text'], [1, 'a', null, true], '1,a,,true'],
    'text object' => [['format' => 'text'], new stdClass, '[object Object]'],
    'number default scale 6' => [[], '1.23456789', '1.234568'],
    'number integer' => [[], 2, '2.000000'],
    'number integral float' => [[], 2.0, '2.000000'],
    'number fractional float' => [[], 2.5, null],
    'number invalid string' => [[], 'abc', null],
    'number bool' => [[], true, null],
    'number null' => [[], null, null],
    'number scale 0 half-up' => [['scale' => 0], '2.5', '3'],
    'number scale 0 away from zero' => [['scale' => 0], '-2.5', '-3'],
    'number negative zero' => [['scale' => 1], '-0.04', '0.0'],
    'money default scale 2' => [['format' => 'money'], '7', '7.00'],
    'money custom scale' => [['format' => 'money', 'scale' => 3], '0.0005', '0.001'],
    'unknown format is number' => [['format' => 'percent'], '1', '1.000000'],
    'number scale 10' => [['scale' => 10], '1', '1.0000000000'],
    'number scale above 10 falls back to 6' => [['scale' => 11], '1', '1.000000'],
    'money scale above 10 falls back to 2' => [['format' => 'money', 'scale' => 11], '1', '1.00'],
    'negative scale falls back' => [['scale' => -1], '1', '1.000000'],
]);

test('EvaluationState tracks values and visibility', function () use ($definition): void {
    $state = new EvaluationState($definition, fs_context(), new JsonLogic, ['title' => 'a', 'items' => [['name' => 'n']]]);

    expect($state->visibility())->toBe([])
        ->and($state->isVisible('/title'))->toBeTrue()
        ->and($state->value([['items', 0]], 'name'))->toBe('n')
        ->and($state->has([['items', 0]], 'line'))->toBeFalse()
        ->and(count($state->instances()))->toBe(10);

    $fingerprint = $state->fingerprint();
    $state->setVisible('/title', false);
    $state->set([['items', 0]], 'line', '1');
    $state->remove([], 'title');

    expect($state->visibility())->toBe(['/title' => false])
        ->and($state->isVisible('/title'))->toBeFalse()
        ->and($state->value([['items', 0]], 'line'))->toBe('1')
        ->and($state->values())->toBe(['items' => [['name' => 'n', 'line' => '1']]])
        ->and($state->fingerprint())->not->toBe($fingerprint)
        ->and($state->definition)->toBe($definition);
});

test('EvaluationState::evaluate sees the current view and yields null for unknown operators', function () use ($definition): void {
    $state = new EvaluationState($definition, fs_context(), new JsonLogic, ['title' => 'a', 'items' => [['name' => 'n']]]);

    expect($state->evaluate(fs_tree(['var' => 'title']), []))->toBe('a')
        ->and($state->evaluate(fs_tree(['cat' => [['var' => 'name'], ['var' => '$row.name'], ['var' => '$root.title']]]), [['items', 0]]))->toBe('nna')
        ->and($state->evaluate(fs_tree(['nope' => [1]]), []))->toBeNull();

    $state->set([], 'title', 'b');
    expect($state->evaluate(fs_tree(['var' => 'title']), []))->toBe('b');

    $state->setVisible('/title', false);
    expect($state->evaluate(fs_tree(['var' => 'title']), []))->toBeNull();

    $state->setVisible('/title', true);
    expect($state->evaluate(fs_tree(['var' => 'title']), []))->toBe('b');
});

test('EvaluationState::evaluate yields null for logic nested deeper than the limit', function (int $depth, ?bool $expected) use ($definition): void {
    $logic = true;
    for ($level = 0; $level < $depth; $level++) {
        $logic = (object) ['!!' => [$logic]];
    }
    $state = new EvaluationState($definition, fs_context(), new JsonLogic, []);

    expect($state->evaluate($logic, []))->toBe($expected);
})->with([
    'shallow' => [100, true],
    'too deep' => [600, null],
]);

test('EvaluationState caches the logic view until data or visibility changes', function () use ($definition): void {
    $logic = new class implements LogicEvaluator
    {
        /** @var list<stdClass> */
        public array $roots = [];

        public function evaluate(mixed $logic, mixed $data): mixed
        {
            $this->roots[] = $data->{'$root'};

            return null;
        }
    };
    $state = new EvaluationState($definition, fs_context(), $logic, ['title' => 'a']);

    $state->evaluate(1, []);
    $state->evaluate(1, []);
    $state->setVisible('/title', true);
    $state->evaluate(1, []);
    $state->setVisible('/title', false);
    $state->evaluate(1, []);
    $state->setVisible('/title', false);
    $state->evaluate(1, []);
    $state->set([], 'title', 'b');
    $state->evaluate(1, []);
    $state->remove([], 'title');
    $state->evaluate(1, []);

    [$first, $cached, $sameVisibility, $hidden, $stillHidden, $changed, $removed] = $logic->roots;

    expect($cached)->toBe($first)
        ->and($sameVisibility)->toBe($first)
        ->and($hidden)->not->toBe($first)
        ->and($stillHidden)->toBe($hidden)
        ->and($changed)->not->toBe($hidden)
        ->and($removed)->not->toBe($changed)
        ->and($hidden->title)->toBeNull();
});
