<?php

declare(strict_types=1);

use Fieldset\Definition\Definition;
use Fieldset\Definition\InvalidDefinitionException;
use Fieldset\Evaluation\EvaluationResult;
use Fieldset\Evaluation\Evaluator;
use Fieldset\Json\Json;
use Fieldset\Logic\LogicEvaluator;
use Fieldset\Logic\NestingTooDeepException;
use Fieldset\Logic\UnknownOperatorException;

require_once __DIR__.'/../Support/helpers.php';

/*
 * End-to-end behaviour of the evaluation algorithm (spec §5.5) and validation (§5.6).
 */

$evaluate = static fn (array $elements, mixed $data, array $extra = []): EvaluationResult => (new Evaluator)->evaluate(fs_definition($elements, $extra), $data, fs_context());

$json = static fn (mixed $value): string => Json::canonical($value);

$money = static fn (string $key, array $properties = []): array => fs_element('money', $key, ['label' => $key, 'currency' => 'EUR', ...$properties]);
$calc = static fn (string $key, mixed $expression, array $properties = []): array => fs_element('calculated', $key, ['label' => $key, 'expression' => $expression, ...$properties]);
$text = static fn (string $key, array $properties = []): array => fs_element('text', $key, ['label' => $key, ...$properties]);

test('input data that is not an object counts as {}', function (mixed $data) use ($evaluate, $json, $text, $calc): void {
    $result = $evaluate([$text('name', ['required' => true]), $calc('one', '1')], $data);

    expect($json($result->data))->toBe('{"one":"1.000000"}')
        ->and($result->errorsToArray())->toBe([['pointer' => '/name', 'code' => 'required']])
        ->and($result->isValid())->toBeFalse();
})->with([
    'null' => [null],
    'string' => ['name'],
    'number' => [5],
    'list' => [fs_tree([['name' => 'x']])],
]);

test('nested repeaters compute row-level calculations with $row and $root', function () use ($evaluate, $json, $text, $money, $calc): void {
    $result = $evaluate([
        $calc('grand_total', ['sum' => [['var' => 'orders'], 'order_total']], ['format' => 'money']),
        $money('rate'),
        $text('label'),
        fs_element('repeater', 'orders', ['label' => 'Orders', 'elements' => [
            $text('label'),
            fs_element('repeater', 'lines', ['label' => 'Lines', 'elements' => [
                fs_element('number', 'qty', ['label' => 'Qty']),
                $calc('line_total', ['dec.mul' => [['var' => 'qty'], ['var' => '$root.rate']]], ['format' => 'money']),
                $calc('tag', ['cat' => [['var' => 'label'], ':', ['var' => '$row.qty'], ':', ['var' => '$root.label']]], ['format' => 'text']),
            ]]),
            $calc('order_total', ['sum' => [['var' => 'lines'], 'line_total']], ['format' => 'money']),
        ]]),
    ], fs_tree([
        'rate' => '1.5',
        'label' => 'root',
        'orders' => [['label' => 'A', 'lines' => [['qty' => '2'], ['qty' => '3']]], ['label' => 'B', 'lines' => []]],
    ]));

    expect($json($result->data))->toBe($json(fs_tree([
        'grand_total' => '7.50',
        'rate' => '1.50',
        'label' => 'root',
        'orders' => [
            ['label' => 'A', 'lines' => [
                ['qty' => '2', 'line_total' => '3.00', 'tag' => 'A:2:root'],
                ['qty' => '3', 'line_total' => '4.50', 'tag' => 'A:3:root'],
            ], 'order_total' => '7.50'],
            ['label' => 'B', 'lines' => [], 'order_total' => '0.00'],
        ],
    ])))->and($result->errors)->toBe([]);
});

test('page-level visibleIf hides the page with its containers and elements', function (bool $show, array $errors, string $data) use ($json, $text): void {
    $definition = fs_definition([], ['pages' => [
        ['key' => 'one', 'title' => 'One', 'elements' => [fs_element('toggle', 'show', ['label' => 'Show'])]],
        ['key' => 'two', 'title' => 'Two', 'visibleIf' => ['var' => 'show'], 'elements' => [
            $text('secret', ['required' => true]),
            fs_element('section', 'group', ['elements' => [$text('inner', ['required' => true])]]),
        ]],
    ]]);
    $result = (new Evaluator)->evaluate($definition, fs_tree(['show' => $show, 'inner' => 'kept?']), fs_context());

    expect($result->errorsToArray())->toBe($errors)
        ->and($json($result->data))->toBe($data)
        ->and($result->visibility)->toBe(['/show' => true, '/secret' => $show, '/group' => $show, '/inner' => $show]);
})->with([
    'hidden page' => [false, [], '{"show":false}'],
    'visible page' => [true, [['pointer' => '/secret', 'code' => 'required']], '{"inner":"kept?","show":true}'],
]);

test('page visibleIf is read from its own page when malformed pages are skipped', function () use ($json, $text): void {
    $definition = fs_definition([], ['pages' => [
        'not a page',
        ['key' => 'one', 'title' => 'One', 'elements' => [$text('a')]],
        ['key' => 'two', 'title' => 'Two', 'visibleIf' => false, 'elements' => [$text('b')]],
    ]]);
    $result = (new Evaluator)->evaluate($definition, fs_tree(['a' => 'x', 'b' => 'y']), fs_context());

    expect($result->visibility)->toBe(['/a' => true, '/b' => false])
        ->and($json($result->data))->toBe('{"a":"x"}');
});

test('requiredIf makes an element required while truthy', function (mixed $status, mixed $requiredIf, array $errors) use ($evaluate, $text): void {
    $result = $evaluate([
        fs_element('select', 'status', ['label' => 'Status', 'options' => [['value' => 'ok', 'label' => 'OK'], ['value' => 'rejected', 'label' => 'No']]]),
        $text('reason', ['required' => false, 'requiredIf' => $requiredIf]),
    ], fs_tree(['status' => $status]));

    expect($result->errorsToArray())->toBe($errors);
})->with([
    'condition true' => ['rejected', ['==' => [['var' => 'status'], 'rejected']], [['pointer' => '/reason', 'code' => 'required']]],
    'condition false' => ['ok', ['==' => [['var' => 'status'], 'rejected']], []],
    'invalid option reads as null' => ['nope', ['!' => ['var' => 'status']], [['pointer' => '/status', 'code' => 'invalid_option'], ['pointer' => '/reason', 'code' => 'required']]],
    'literal true' => [null, true, [['pointer' => '/reason', 'code' => 'required']]],
    'unknown operator is null' => ['rejected', ['nope' => [1]], []],
]);

test('defaults: chained defaults settle over rounds, in document order', function () use ($evaluate, $json, $text): void {
    $result = $evaluate([
        $text('alias', ['default' => ['var' => 'name']]),
        $text('greeting', ['default' => ['cat' => ['Hello ', ['var' => 'name']]]]),
        $text('name', ['default' => ['var' => '$user.name']]),
        $text('given', ['default' => 'unused']),
        $text('nulled', ['default' => ['var' => '$today']]),
    ], fs_tree(['given' => 'mine', 'nulled' => null]));

    expect($json($result->data))->toBe($json(fs_tree([
        'alias' => 'Willi Worker',
        'greeting' => 'Hello ',
        'name' => 'Willi Worker',
        'given' => 'mine',
        'nulled' => '2026-10-16',
    ])));
});

test('defaults: a default can make an earlier element visible in a later round', function () use ($evaluate, $json, $text): void {
    $result = $evaluate([
        $text('extra', ['visibleIf' => ['var' => 'flag'], 'default' => 'filled']),
        fs_element('toggle', 'flag', ['label' => 'Flag', 'default' => true]),
    ], new stdClass);

    expect($json($result->data))->toBe('{"extra":"filled","flag":true}')
        ->and($result->visibility)->toBe(['/extra' => true, '/flag' => true]);
});

test('defaults are used as-is, money is normalized, repeater rows are cleaned', function () use ($evaluate, $json, $text, $money, $calc): void {
    $result = $evaluate([
        fs_element('number', 'count', ['label' => 'Count', 'default' => 5]),
        $money('price', ['default' => '5']),
        $money('fee', ['default' => '5.555']),
        $calc('calc', '1', ['default' => '2', 'format' => 'text']),
        $text('invisible', ['visibleIf' => false, 'default' => 'x']),
        $text('unknown_op', ['default' => ['nope' => []]]),
        fs_element('repeater', 'rows', ['label' => 'Rows', 'default' => [['desc' => 'x', 'junk' => 1], 5], 'elements' => [
            $text('desc'),
            $calc('echo', ['var' => 'desc'], ['format' => 'text']),
        ]]),
        fs_element('repeater', 'bad_rows', ['label' => 'Bad', 'default' => 'nope', 'elements' => [$text('desc')]]),
    ], new stdClass);

    expect($json($result->data))->toBe($json(fs_tree([
        'count' => 5,
        'price' => '5.00',
        'fee' => '5.555',
        'calc' => '1',
        'rows' => [['desc' => 'x', 'echo' => 'x']],
    ])))->and($result->errorsToArray())->toBe([
        ['pointer' => '/count', 'code' => 'decimal_string_required'],
        ['pointer' => '/fee', 'code' => 'scale_exceeded', 'params' => ['scale' => 2]],
    ]);
});

test('an invisible repeater is stripped with its rows and not validated', function (bool $has, string $data, array $errors) use ($evaluate, $json, $text, $calc): void {
    $result = $evaluate([
        fs_element('toggle', 'has_items', ['label' => 'Has']),
        fs_element('repeater', 'items', ['label' => 'Items', 'required' => true, 'minItems' => 3, 'visibleIf' => ['var' => 'has_items'], 'elements' => [
            $text('name', ['required' => true]),
            $calc('up', ['var' => 'name'], ['format' => 'text']),
        ]]),
    ], fs_tree(['has_items' => $has, 'items' => [['name' => ''], ['name' => 'x', 'up' => 'client']]]));

    expect($json($result->data))->toBe($data)
        ->and($result->errorsToArray())->toBe($errors)
        ->and($result->visibility)->toBe([
            '/has_items' => true, '/items' => $has,
            '/items/0/name' => $has, '/items/0/up' => $has, '/items/1/name' => $has, '/items/1/up' => $has,
        ]);
})->with([
    'invisible' => [false, '{"has_items":false}', []],
    'visible' => [true, '{"has_items":true,"items":[{"name":"","up":""},{"name":"x","up":"x"}]}', [
        ['pointer' => '/items', 'code' => 'min_items', 'params' => ['limit' => 3]],
        ['pointer' => '/items/0/name', 'code' => 'required'],
    ]],
]);

test('visibility maps every element pointer, layout elements and rows included', function () use ($evaluate, $json, $text): void {
    $result = $evaluate([
        fs_element('heading', 'intro', ['text' => 'Intro']),
        fs_element('section', 'sec', ['visibleIf' => false, 'elements' => [
            fs_element('columns', 'cols', ['columns' => [['elements' => [$text('left')]], ['elements' => [$text('right')]]]]),
        ]]),
        fs_element('divider', 'div'),
        fs_element('repeater', 'rows', ['label' => 'Rows', 'elements' => [
            fs_element('paragraph', 'hint', ['text' => 'Hint', 'visibleIf' => ['var' => 'note']]),
            $text('note'),
        ]]),
    ], fs_tree(['intro' => 'x', 'sec' => 'y', 'left' => 'l', 'rows' => [['note' => 'n', 'hint' => 'h'], new stdClass]]));

    expect($result->visibility)->toBe([
        '/intro' => true, '/sec' => false, '/cols' => false, '/left' => false, '/right' => false, '/div' => true,
        '/rows' => true, '/rows/0/hint' => true, '/rows/0/note' => true, '/rows/1/hint' => false, '/rows/1/note' => true,
    ])->and($json($result->data))->toBe('{"rows":[{"note":"n"},{}]}');
});

test('calculated values are converted by format and scale', function (mixed $expression, array $properties, ?string $expected) use ($evaluate, $calc): void {
    $result = $evaluate([
        fs_element('toggle', 'ok', ['label' => 'OK']),
        fs_element('multiselect', 'tags', ['label' => 'Tags', 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]]),
        $calc('result', $expression, $properties),
    ], fs_tree(['ok' => true, 'tags' => ['a', 'b']]));

    expect($result->data->result)->toBe($expected);
})->with([
    'text from true' => [['var' => 'ok'], ['format' => 'text'], 'true'],
    'text from false' => [['!' => ['var' => 'ok']], ['format' => 'text'], 'false'],
    'text from an array' => [['var' => 'tags'], ['format' => 'text'], 'a,b'],
    'text from a float' => [['+' => [1, 0.5]], ['format' => 'text'], '1.5'],
    'text from an integer' => [3, ['format' => 'text'], '3'],
    'text from null' => [['var' => 'tags.5'], ['format' => 'text'], null],
    'text from an object' => [['var' => '$org'], ['format' => 'text'], '[object Object]'],
    'number default scale 6' => ['1.23456789', [], '1.234568'],
    'number scale 0 rounds half-up' => ['2.5', ['scale' => 0], '3'],
    'number rounds away from zero' => ['-2.5', ['scale' => 0], '-3'],
    'number from a JSON integer' => [42, [], '42.000000'],
    'number negative zero' => ['-0.001', ['scale' => 2], '0.00'],
    'number from a fraction' => [1.5, [], null],
    'number from a bool' => [true, [], null],
    'number from text' => ['abc', [], null],
    'money default scale 2' => ['7', ['format' => 'money'], '7.00'],
    'money scale 4' => ['0.00005', ['format' => 'money', 'scale' => 4], '0.0001'],
    'unknown format is number' => ['1', ['format' => 'percent', 'scale' => 1], '1.0'],
]);

test('logic with an unknown operator evaluates to null', function () use ($evaluate, $json, $text, $calc): void {
    $result = $evaluate([
        $text('a', ['visibleIf' => ['nope' => [1]]]),
        $text('b', ['default' => ['nope' => []]]),
        $calc('c', ['nope' => 1], ['format' => 'text']),
        $text('d', ['requiredIf' => ['nope' => 1]]),
        $calc('e', ['if' => [['nope' => 1], 'yes', 'no']], ['format' => 'text']),
    ], fs_tree(['a' => 'stripped']));

    expect($result->visibility['/a'])->toBeFalse()
        ->and($json($result->data))->toBe('{"c":null,"e":null}')
        ->and($result->errors)->toBe([]);
});

test('hidden keeps valid client values; invalid ones get the default or type_mismatch', function (mixed $value, string $data, array $errors) use ($evaluate, $json): void {
    $result = $evaluate([
        fs_element('hidden', 'with_default', ['label' => 'H', 'default' => 'fallback']),
        fs_element('hidden', 'plain', ['label' => 'P']),
        fs_element('hidden', 'bad_default', ['label' => 'B', 'default' => 1.5]),
    ], fs_tree(['with_default' => $value, 'plain' => $value, 'bad_default' => $value]));

    expect($json($result->data))->toBe($data)
        ->and(fs_error_set($result->errors))->toBe($errors);
})->with([
    'string' => ['abc', '{"bad_default":"abc","plain":"abc","with_default":"abc"}', []],
    'integer' => [7, '{"bad_default":7,"plain":7,"with_default":7}', []],
    'false' => [false, '{"bad_default":false,"plain":false,"with_default":false}', []],
    'null' => [null, '{"bad_default":1.5,"plain":null,"with_default":"fallback"}', ['/bad_default|type_mismatch']],
    'fraction' => [2.5, '{"bad_default":1.5,"plain":2.5,"with_default":"fallback"}', ['/bad_default|type_mismatch', '/plain|type_mismatch']],
    'array' => [['a'], '{"bad_default":1.5,"plain":["a"],"with_default":"fallback"}', ['/bad_default|type_mismatch', '/plain|type_mismatch']],
    'unsafe integer' => [2 ** 60, '{"bad_default":1.5,"plain":1152921504606847000,"with_default":"fallback"}', ['/bad_default|type_mismatch', '/plain|type_mismatch']],
]);

test('normalization errors come first and are reported even for invisible repeaters', function () use ($evaluate, $json, $text): void {
    $rows = static fn (string $key, array $properties = []): array => fs_element('repeater', $key, ['label' => $key, 'elements' => [
        $text('name', ['required' => true]),
        fs_element('repeater', 'parts', ['label' => 'Parts', 'elements' => [$text('qty')]]),
    ], ...$properties]);
    $result = $evaluate([
        $text('first', ['required' => true]),
        $rows('items'),
        $rows('hidden_items', ['visibleIf' => false]),
        $rows('nulled', ['required' => true]),
    ], fs_tree([
        'items' => ['junk', ['name' => 'a', 'parts' => [['qty' => 'x'], 7]], ['name' => 'b', 'parts' => 'bad']],
        'hidden_items' => 'not rows',
        'nulled' => null,
    ]));

    expect($result->errorsToArray())->toBe([
        ['pointer' => '/items/0', 'code' => 'type_mismatch'],
        ['pointer' => '/items/0/parts/1', 'code' => 'type_mismatch'],
        ['pointer' => '/items/1/parts', 'code' => 'type_mismatch'],
        ['pointer' => '/hidden_items', 'code' => 'type_mismatch'],
        ['pointer' => '/first', 'code' => 'required'],
        ['pointer' => '/nulled', 'code' => 'required'],
    ])->and($json($result->data))->toBe('{"items":[{"name":"a","parts":[{"qty":"x"}]},{"name":"b","parts":[]}],"nulled":null}');
});

test('repeater row counts are checked without rows; required reports required only', function () use ($evaluate, $json): void {
    $repeater = static fn (string $key, array $properties): array => fs_element('repeater', $key, ['label' => $key, 'elements' => [], ...$properties]);
    $result = $evaluate([
        $repeater('missing', ['minItems' => 1]),
        $repeater('nulled', ['minItems' => 1]),
        $repeater('many', ['maxItems' => 1]),
        $repeater('needed', ['required' => true, 'minItems' => 2]),
    ], fs_tree(['nulled' => null, 'many' => [new stdClass, new stdClass], 'needed' => []]));

    expect($result->errorsToArray())->toBe([
        ['pointer' => '/missing', 'code' => 'min_items', 'params' => ['limit' => 1]],
        ['pointer' => '/nulled', 'code' => 'min_items', 'params' => ['limit' => 1]],
        ['pointer' => '/many', 'code' => 'max_items', 'params' => ['limit' => 1]],
        ['pointer' => '/needed', 'code' => 'required'],
    ])->and($json($result->data))->toBe('{"many":[{},{}],"needed":[],"nulled":null}');
});

test('errors are listed in document order, rows in index order', function () use ($evaluate, $text): void {
    $result = $evaluate([
        $text('first', ['required' => true]),
        fs_element('repeater', 'rows', ['label' => 'Rows', 'elements' => [$text('a', ['required' => true]), $text('b', ['required' => true])]]),
        $text('last', ['required' => true]),
    ], fs_tree(['rows' => [new stdClass, ['b' => 'x'], new stdClass]]));

    expect(array_map(static fn (array $e): string => $e['pointer'], $result->errorsToArray()))
        ->toBe(['/first', '/rows/0/a', '/rows/0/b', '/rows/1/a', '/rows/2/a', '/rows/2/b', '/last']);
});

test('evaluation stops after five rounds when visibility keeps flipping', function () use ($evaluate, $json): void {
    $result = $evaluate([
        fs_element('toggle', 'flip', ['label' => 'Flip', 'default' => true, 'visibleIf' => ['!' => ['var' => 'flip']]]),
    ], new stdClass);

    expect(Evaluator::MAX_ROUNDS)->toBe(5)
        ->and($result->visibility)->toBe(['/flip' => true])
        ->and($json($result->data))->toBe('{"flip":true}');
});

test('the evaluator never mutates its inputs and never shares objects with them', function () use ($json, $text, $money, $calc): void {
    $definition = fs_definition([
        $money('price'),
        fs_element('signature', 'sig', ['label' => 'Sig']),
        fs_element('repeater', 'rows', ['label' => 'Rows', 'elements' => [$text('name'), $calc('echo', ['var' => 'name'], ['format' => 'text'])]]),
    ]);
    $data = fs_tree([
        'price' => '1.5',
        'unknown' => ['deep' => true],
        'sig' => ['attachment' => '0b6e4d3c-8f1a-4c2b-9d7e-1a2b3c4d5e6f', 'signerName' => 'W', 'signedAt' => '2026-10-16T09:41:00Z'],
        'rows' => [['name' => 'a', 'echo' => 'client', 'junk' => 1], 'not a row'],
    ]);
    [$definitionBefore, $dataBefore] = [$json($definition), $json($data)];

    $result = (new Evaluator)->evaluate($definition, $data, fs_context());

    expect($json($definition))->toBe($definitionBefore)
        ->and($json($data))->toBe($dataBefore)
        ->and($result->data->price)->toBe('1.50')
        ->and($result->data->sig)->toEqual($data->sig)
        ->and($result->data->sig)->not->toBe($data->sig)
        ->and($result->data->rows[0])->not->toBe($data->rows[0]);

    $result->data->sig->signerName = 'changed';
    $result->data->rows[0]->name = 'changed';

    expect($json($data))->toBe($dataBefore);
});

test('a custom LogicEvaluator receives the logic and the scope', function () use ($json, $text, $calc): void {
    $logic = new class implements LogicEvaluator
    {
        /** @var list<array{mixed, mixed}> */
        public array $calls = [];

        public function evaluate(mixed $logic, mixed $data): mixed
        {
            $this->calls[] = [$logic, $data];

            return match ($logic) {
                'hide' => false,
                'boom' => throw new UnknownOperatorException('boom'),
                'deep' => throw new NestingTooDeepException(512),
                default => 'custom',
            };
        }
    };
    $result = (new Evaluator($logic))->evaluate(fs_definition([
        $text('a', ['visibleIf' => 'hide']),
        $text('b', ['default' => 'anything']),
        $calc('c', 'boom', ['format' => 'text']),
        $calc('d', 'deep', ['format' => 'text']),
    ]), fs_tree(['a' => 'x']), fs_context());

    [$firstLogic, $scope] = $logic->calls[0];

    expect($json($result->data))->toBe('{"b":"custom","c":null,"d":null}')
        ->and($result->visibility)->toBe(['/a' => false, '/b' => true, '/c' => true, '/d' => true])
        ->and($firstLogic)->toBe('hide')
        ->and($scope->{'$today'})->toBe('2026-10-16')
        ->and($scope->{'$form'}->version)->toBe(3)
        ->and(property_exists($scope, '$root'))->toBeTrue()
        ->and(property_exists($scope, '$row'))->toBeFalse()
        ->and(array_column($logic->calls, 0))->toContain('anything', 'boom');
});

test('a parsed Definition is accepted instead of its JSON', function () use ($json, $text, $calc): void {
    $tree = fs_definition([$text('name', ['default' => 'x']), $calc('echo', ['var' => 'name'], ['format' => 'text'])]);
    $fromJson = (new Evaluator)->evaluate($tree, new stdClass, fs_context());
    $fromDefinition = (new Evaluator)->evaluate(Definition::fromJson($tree), new stdClass, fs_context());

    expect($json($fromDefinition->data))->toBe('{"echo":"x","name":"x"}')
        ->and($fromDefinition)->toEqual($fromJson);
});

test('a definition that is not a JSON object is rejected', function (): void {
    (new Evaluator)->evaluate('not a definition', new stdClass, fs_context());
})->throws(InvalidDefinitionException::class);
