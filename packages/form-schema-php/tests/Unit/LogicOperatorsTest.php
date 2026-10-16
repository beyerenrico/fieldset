<?php

declare(strict_types=1);

use FormSchema\Json\Json;
use FormSchema\Logic\JsonLogic;
use FormSchema\Logic\LogicDepth;
use FormSchema\Logic\LogicError;
use FormSchema\Logic\NestingTooDeepException;
use FormSchema\Logic\OperatorNames;
use FormSchema\Logic\UnknownOperatorException;

require_once __DIR__.'/../Support/helpers.php';

describe('JsonLogic', function (): void {
    test('returns literals and evaluates arrays element-wise', function (string $logic, string $data, mixed $expected): void {
        expect((new JsonLogic)->evaluate(Json::decode($logic), Json::decode($data)))->toBe($expected);
    })->with([
        'int' => ['5', '{}', 5],
        'string' => ['"x"', '{}', 'x'],
        'null' => ['null', '{}', null],
        'bool' => ['true', '{}', true],
        'array' => ['[{"var": "a"}, 2, [{"var": "a"}]]', '{"a": 1}', [1, 2, [1]]],
    ]);

    test('returns objects that are not a single-key operation literally', function (string $json): void {
        $logic = Json::decode($json);

        expect((new JsonLogic)->evaluate($logic, new stdClass))->toBe($logic);
    })->with([
        'multi-key object' => ['{"a": {"var": "x"}, "b": 1}'],
        'empty object' => ['{}'],
    ]);

    test('evaluates PHP associative arrays as lists of their values', function (): void {
        $logic = ['x' => 1, 'y' => fs_tree(['var' => 'a'])];

        expect((new JsonLogic)->evaluate($logic, fs_tree(['a' => 2])))->toBe([1, 2]);
    });

    test('evaluateStrict throws UnknownOperatorException; evaluate makes the expression null', function (string $logic, string $operator): void {
        expect((new JsonLogic)->evaluate(Json::decode($logic), new stdClass))->toBeNull();

        try {
            (new JsonLogic)->evaluateStrict(Json::decode($logic), new stdClass);
            $this->fail('Expected UnknownOperatorException');
        } catch (UnknownOperatorException $exception) {
            expect($exception->operator)->toBe($operator)
                ->and($exception->reason)->toBe(LogicError::UNKNOWN_OPERATOR)
                ->and($exception->getMessage())->toBe(sprintf('Unknown JsonLogic operator "%s".', $operator))
                ->and($exception)->toBeInstanceOf(LogicError::class)
                ->and($exception)->toBeInstanceOf(RuntimeException::class);
        }
    })->with([
        'top level' => ['{"nope": [1]}', 'nope'],
        'nested in arguments' => ['{"cat": ["a", {"Var": "x"}]}', 'Var'],
        'JsonLogic built-in outside the dialect' => ['{"log": "x"}', 'log'],
    ]);

    test('LogicError carries reason, operator and message', function (): void {
        $error = new LogicError('custom', 'op', 'Message');

        expect($error->reason)->toBe('custom')
            ->and($error->operator)->toBe('op')
            ->and($error->getMessage())->toBe('Message')
            ->and(LogicError::UNKNOWN_OPERATOR)->toBe('unknown_operator')
            ->and(LogicError::MAX_DEPTH)->toBe('max_depth');
    });

    test('evaluates expressions nested exactly MAX_DEPTH levels', function (Closure $wrap): void {
        $logic = true;
        for ($i = 0; $i < JsonLogic::MAX_DEPTH; $i++) {
            $logic = $wrap($logic);
        }

        expect(JsonLogic::MAX_DEPTH)->toBe(512)
            ->and((new JsonLogic)->evaluate($logic, null))->not->toBeNull()
            ->and((new JsonLogic)->evaluateStrict($logic, null))->not->toBeNull();
    })->with([
        'arrays' => [static fn (mixed $inner): array => [$inner]],
        'operations' => [static fn (mixed $inner): stdClass => (object) ['!' => $inner]],
    ]);

    test('nesting beyond MAX_DEPTH is null, evaluateStrict throws, and the evaluator recovers', function (Closure $wrap): void {
        $logic = true;
        for ($i = 0; $i <= JsonLogic::MAX_DEPTH; $i++) {
            $logic = $wrap($logic);
        }

        $evaluator = new JsonLogic;
        expect($evaluator->evaluate($logic, null))->toBeNull();

        try {
            $evaluator->evaluateStrict($logic, null);
            $this->fail('Expected NestingTooDeepException');
        } catch (NestingTooDeepException $exception) {
            expect($exception->reason)->toBe(LogicError::MAX_DEPTH)
                ->and($exception->operator)->toBe('')
                ->and($exception->getMessage())->toBe('Logic nesting exceeds 512 levels.')
                ->and($exception)->toBeInstanceOf(LogicError::class);
        }

        expect($evaluator->evaluate(fs_tree(['!' => [false]]), null))->toBeTrue()
            ->and($evaluator->evaluate(is_array($logic) ? $logic[0] : $logic->{'!'}, null))->not->toBeNull();
    })->with([
        'arrays' => [static fn (mixed $inner): array => [$inner]],
        'operations' => [static fn (mixed $inner): stdClass => (object) ['!' => $inner]],
    ]);

    test('LogicDepth counts objects and arrays', function (): void {
        expect(LogicDepth::of(1))->toBe(0)
            ->and(LogicDepth::of([]))->toBe(1)
            ->and(LogicDepth::of(fs_tree(['!' => [true]])))->toBe(2)
            ->and(LogicDepth::of(fs_tree(['a' => 1, 'b' => [[1]]])))->toBe(3)
            ->and(LogicDepth::of([[[1]]], 2))->toBe(2)
            ->and(LogicDepth::of([1], 0))->toBe(0)
            ->and(LogicDepth::exceeds([[1]], 1))->toBeTrue()
            ->and(LogicDepth::exceeds([[1]], 2))->toBeFalse();
    });

    test('does not evaluate branches that are not taken', function (string $logic, mixed $expected): void {
        expect((new JsonLogic)->evaluate(Json::decode($logic), new stdClass))->toBe($expected);
    })->with([
        'if' => ['{"if": [false, {"nope": 1}, "ok"]}', 'ok'],
        'or' => ['{"or": [1, {"nope": 1}]}', 1],
        'and' => ['{"and": [0, {"nope": 1}]}', 0],
    ]);

    test('isOperation is true only for single-key objects', function (mixed $logic, bool $expected): void {
        expect(JsonLogic::isOperation($logic))->toBe($expected);
    })->with([
        'operation' => [fs_tree(['var' => 'a']), true],
        'multi-key object' => [fs_tree(['a' => 1, 'b' => 2]), false],
        'empty object' => [new stdClass, false],
        'list' => [[1], false],
        'string' => ['var', false],
    ]);

    test('split wraps non-list arguments', function (string $json, string $operator, array $args): void {
        expect(JsonLogic::split(Json::decode($json)))->toBe([$operator, $args]);
    })->with([
        'list' => ['{"cat": ["a", "b"]}', 'cat', ['a', 'b']],
        'scalar' => ['{"var": "x"}', 'var', ['x']],
        'null' => ['{"x": null}', 'x', [null]],
        'numeric operator name' => ['{"1": [2]}', '1', [2]],
    ]);

    test('split wraps associative arrays and objects', function (): void {
        $object = fs_tree(['var' => 'a']);

        expect(JsonLogic::split((object) ['cat' => ['k' => 'v']]))->toBe(['cat', [['k' => 'v']]])
            ->and(JsonLogic::split((object) ['var' => $object]))->toBe(['var', [$object]]);
    });
});

describe('OperatorNames', function (): void {
    test('isSupported', function (string $operator, bool $expected): void {
        expect(OperatorNames::isSupported($operator))->toBe($expected);
    })->with([
        ['var', true], ['?:', true], ['missing_some', true], ['map', true], ['reduce', true],
        ['dec.add', true], ['dec.lte', true], ['sum', true], ['min', true], ['max', true],
        ['count', true], ['date.diff', true], ['date.add', true], ['%', true],
        ['foo', false], ['Var', false], ['log', false], ['method', false], ['', false],
    ]);

    test('all lists every operator exactly once', function (): void {
        $all = OperatorNames::all();

        expect($all)->toHaveCount(48)
            ->and(array_is_list($all))->toBeTrue()
            ->and(array_unique($all))->toBe($all)
            ->and($all)->toContain('var', 'if', 'map', 'dec.div', 'min', 'count', 'date.add');

        foreach ([...OperatorNames::ITERATION, ...OperatorNames::DECIMAL, ...OperatorNames::DECIMAL_SENSITIVE_BUILTINS] as $name) {
            expect(OperatorNames::isSupported($name))->toBeTrue();
        }

        expect(array_diff(OperatorNames::ROW_KEY_AGGREGATES, OperatorNames::DECIMAL))->toBe([]);
    });

    test('every listed operator is implemented by JsonLogic', function (): void {
        $logic = new JsonLogic;
        $evaluated = [];

        foreach (OperatorNames::all() as $name) {
            $logic->evaluate((object) [$name => []], new stdClass);
            $evaluated[] = $name;
        }

        expect($evaluated)->toBe(OperatorNames::all());
    });
});

test('variable operators', function (string $logic, string $data, mixed $expected): void {
    expect((new JsonLogic)->evaluate(Json::decode($logic), Json::decode($data)))->toBe($expected);
})->with([
    'dotted path' => ['{"var": "a.b"}', '{"a": {"b": 1}}', 1],
    'missing with fallback' => ['{"var": ["a.c", "fb"]}', '{"a": {"b": 1}}', 'fb'],
    'null with fallback' => ['{"var": ["a.b", "fb"]}', '{"a": {"b": null}}', 'fb'],
    'false is a value' => ['{"var": ["a", "fb"]}', '{"a": false}', false],
    'empty string is a value' => ['{"var": ["a", "fb"]}', '{"a": ""}', ''],
    'zero is a value' => ['{"var": ["a", "fb"]}', '{"a": 0}', 0],
    'no fallback' => ['{"var": ["a"]}', '{}', null],
    'array index' => ['{"var": "items.1"}', '{"items": [10, 20]}', 20],
    'leading zero index' => ['{"var": "items.01"}', '{"items": [10, 20]}', null],
    'index out of range' => ['{"var": "items.5"}', '{"items": [10, 20]}', null],
    'non-numeric index' => ['{"var": "items.x"}', '{"items": [10, 20]}', null],
    'numeric path' => ['{"var": 1}', '[10, 20]', 20],
    'integral float path' => ['{"var": 1.0}', '[10, 20]', 20],
    'index then property' => ['{"var": "0.a"}', '[{"a": "x"}]', 'x'],
    'numeric object key' => ['{"var": "1"}', '{"1": "one"}', 'one'],
    'below a scalar' => ['{"var": ["a.b", "fb"]}', '{"a": 5}', 'fb'],
    'scope is a string' => ['{"var": "a"}', '"str"', null],
    'scope is null' => ['{"var": "a"}', 'null', null],
    'fallback is evaluated' => ['{"var": ["missing", {"cat": ["d", "e"]}]}', '{}', 'de'],
    'dynamic path' => ['{"var": {"cat": ["a", ".b"]}}', '{"a": {"b": 7}}', 7],
    'empty path is the scope' => ['{"var": ["", "fb"]}', '5', 5],
    'null path is the scope' => ['{"var": null}', '5', 5],
    'no path is the scope' => ['{"var": []}', '5', 5],
    'empty array path is a missing key' => ['{"var": [[]]}', '5', null],
    'empty array path reads the empty key' => ['{"var": [[], "fb"]}', '{"": "empty key"}', 'empty key'],
    'array value' => ['{"var": "a.b"}', '{"a": {"b": [1, 2]}}', [1, 2]],
    'missing keys' => ['{"missing": ["a", "b", "c"]}', '{"a": 1, "b": null, "c": ""}', ['b', 'c']],
    'missing from an array argument' => ['{"missing": [["a", "b"]]}', '{"b": 1}', ['a']],
    'missing uses only the first array' => ['{"missing": [["a"], "b"]}', '{}', ['a']],
    'missing without keys' => ['{"missing": []}', '{}', []],
    'missing single key' => ['{"missing": "a"}', '{}', ['a']],
    'missing nested present' => ['{"missing": ["a.b"]}', '{"a": {"b": 0}}', []],
    'missing nested absent' => ['{"missing": ["a.b"]}', '{"a": {}}', ['a.b']],
    'missing null key reads the scope' => ['{"missing": [null, "x"]}', '{"y": 1}', ['x']],
    'missing dynamic key list' => ['{"missing": {"var": "keys"}}', '{"keys": ["a", "z"], "a": 1}', ['z']],
    'missing_some satisfied' => ['{"missing_some": [1, ["a", "b", "c"]]}', '{"a": 1}', []],
    'missing_some unsatisfied' => ['{"missing_some": [2, ["a", "b", "c"]]}', '{"a": 1}', ['b', 'c']],
    'missing_some needs none' => ['{"missing_some": [0, ["a"]]}', '{}', []],
    'missing_some non-array options' => ['{"missing_some": [1, "a"]}', '{}', []],
    'missing_some without arguments' => ['{"missing_some": []}', '{}', []],
    'missing_some string count' => ['{"missing_some": ["2", ["a", "b"]]}', '{"a": 1}', ['b']],
]);

test('var returns the scope object itself for an empty path', function (): void {
    $data = fs_tree(['a' => 1]);

    expect((new JsonLogic)->evaluate(fs_tree(['var' => '']), $data))->toBe($data);
});

test('var reads PHP associative arrays and lists', function (string $path, mixed $expected): void {
    $data = ['a' => ['b' => 3, 'items' => ['x', 'y']]];

    expect((new JsonLogic)->evaluate(fs_tree(['var' => [$path, 'fb']]), $data))->toBe($expected);
})->with([
    ['a.b', 3],
    ['a.c', 'fb'],
    ['a.items.1', 'y'],
    ['a.items.2', 'fb'],
]);

test('control operators', function (string $logic, string $data, mixed $expected): void {
    expect((new JsonLogic)->evaluate(Json::decode($logic), Json::decode($data)))->toBe($expected);
})->with([
    ['{"if": []}', '{}', null],
    ['{"if": ["only"]}', '{}', 'only'],
    ['{"if": [true, "yes"]}', '{}', 'yes'],
    ['{"if": [false, "yes"]}', '{}', null],
    ['{"if": [0, "yes", "no"]}', '{}', 'no'],
    ['{"if": [[], "yes", "no"]}', '{}', 'no'],
    ['{"if": ["0", "yes", "no"]}', '{}', 'yes'],
    ['{"if": [false, "a", true, "b", "c"]}', '{}', 'b'],
    ['{"if": [false, "a", false, "b", "c"]}', '{}', 'c'],
    ['{"if": [false, "a", false, "b"]}', '{}', null],
    ['{"if": [{"var": "x"}, {"var": "y"}, "no"]}', '{"x": 1, "y": "Y"}', 'Y'],
    ['{"?:": [true, 1, 2]}', '{}', 1],
    ['{"and": []}', '{}', null],
    ['{"and": [true, 0, "x"]}', '{}', 0],
    ['{"and": [1, "a"]}', '{}', 'a'],
    ['{"or": []}', '{}', null],
    ['{"or": [0, "", "a", "b"]}', '{}', 'a'],
    ['{"or": [0, ""]}', '{}', ''],
    ['{"or": [0, false, null]}', '{}', null],
    ['{"!": [true]}', '{}', false],
    ['{"!": true}', '{}', false],
    ['{"!": []}', '{}', true],
    ['{"!": [[]]}', '{}', true],
    ['{"!": [{"var": "a"}]}', '{"a": "0"}', false],
    ['{"!!": ["0"]}', '{}', true],
    ['{"!!": [[]]}', '{}', false],
    ['{"!!": []}', '{}', false],
    ['{"!!": [{}]}', '{}', true],
]);

test('string and array operators', function (string $logic, mixed $expected): void {
    expect((new JsonLogic)->evaluate(Json::decode($logic), new stdClass))->toBe($expected);
})->with([
    ['{"in": ["ell", "hello"]}', true],
    ['{"in": ["xyz", "hello"]}', false],
    ['{"in": ["", "abc"]}', true],
    ['{"in": [1, "a1b"]}', true],
    ['{"in": [1.5, "x1.5"]}', true],
    ['{"in": [null, "nullable"]}', true],
    ['{"in": ["a", ""]}', false],
    ['{"in": ["", ""]}', true],
    ['{"in": [[], "abc"]}', true],
    ['{"in": [1, [1.0, 2]]}', true],
    ['{"in": ["1", [1]]}', false],
    ['{"in": [null, [null]]}', true],
    ['{"in": [true, [true]]}', true],
    ['{"in": [[1], [[1]]]}', false],
    ['{"in": ["a", null]}', false],
    ['{"in": ["a", 5]}', false],
    ['{"in": ["a"]}', false],
    ['{"cat": []}', ''],
    ['{"cat": "solo"}', 'solo'],
    ['{"cat": ["a", null, 1, 2.5, true, false, [1, 2]]}', 'a12.5truefalse1,2'],
    ['{"cat": [1.0, 1e21, 0.000001, 1e-7]}', '11e+210.0000011e-7'],
    ['{"cat": [{}]}', '[object Object]'],
    ['{"substr": ["hello", 1]}', 'ello'],
    ['{"substr": ["hello", -2]}', 'lo'],
    ['{"substr": ["hello", 1, 2]}', 'el'],
    ['{"substr": ["hello", 1, -1]}', 'ell'],
    ['{"substr": ["hello", -3, -1]}', 'll'],
    ['{"substr": ["hello", 10]}', ''],
    ['{"substr": ["hello", -10]}', 'hello'],
    ['{"substr": ["hello", 1, -10]}', ''],
    ['{"substr": ["hello", 1, null]}', ''],
    ['{"substr": ["hello", 1, 0]}', ''],
    ['{"substr": ["hello", "abc"]}', 'hello'],
    ['{"substr": ["hello", "2"]}', 'llo'],
    ['{"substr": ["hello", true]}', 'ello'],
    ['{"substr": ["hello", [1]]}', 'hello'],
    ['{"substr": ["hello", 1.7]}', 'ello'],
    ['{"substr": ["hello", -1.7]}', 'o'],
    ['{"substr": ["hello", 1e300]}', ''],
    ['{"substr": ["hello", "Infinity"]}', 'hello'],
    ['{"substr": ["hello", 1, "Infinity"]}', ''],
    ['{"substr": ["hello", 1, "-Infinity"]}', ''],
    ['{"substr": ["hello", -1e300]}', 'hello'],
    ['{"substr": ["hello", 2, 1e300]}', 'llo'],
    ['{"substr": ["hello"]}', 'hello'],
    ['{"substr": []}', ''],
    ['{"substr": [12345, 1, 2]}', '23'],
    ['{"substr": [null, 0, 2]}', ''],
    ['{"substr": [true, 0, 2]}', 'tr'],
    ['{"substr": [[1, 2], 0, 3]}', '1,2'],
    ['{"substr": ["", 0, 1]}', ''],
    ['{"substr": ["héllo wörld", 1, 4]}', 'éllo'],
    ['{"substr": ["a😀b", 1, 1]}', '😀'],
    ['{"substr": ["日本語テキスト", -3]}', 'キスト'],
    ['{"merge": []}', []],
    ['{"merge": [[1, 2], 3, [[4]]]}', [1, 2, 3, [4]]],
    ['{"merge": [null, "a"]}', [null, 'a']],
    ['{"merge": [[]]}', []],
    ['{"merge": 5}', [5]],
]);

test('iteration operators', function (string $logic, string $data, mixed $expected): void {
    expect((new JsonLogic)->evaluate(Json::decode($logic), Json::decode($data)))->toBe($expected);
})->with([
    'map' => ['{"map": [[1, 2, 3], {"*": [{"var": ""}, 2]}]}', '{}', [2.0, 4.0, 6.0]],
    'map uses the item scope' => ['{"map": [{"var": "xs"}, {"var": "x"}]}', '{"xs": [1, 2], "x": 5}', [null, null]],
    'map over a non-array' => ['{"map": [5, {"var": ""}]}', '{}', []],
    'map over null' => ['{"map": [null, 1]}', '{}', []],
    'map without arguments' => ['{"map": []}', '{}', []],
    'map without body' => ['{"map": [[1, 2]]}', '{}', [null, null]],
    'filter' => ['{"filter": [[1, 2, 3], {">": [{"var": ""}, 1]}]}', '{}', [2, 3]],
    'filter over a string' => ['{"filter": ["abc", true]}', '{}', []],
    'all true' => ['{"all": [[1, 2], {"var": ""}]}', '{}', true],
    'all with a falsy item' => ['{"all": [[1, 0], {"var": ""}]}', '{}', false],
    'all on empty' => ['{"all": [[], true]}', '{}', false],
    'all on non-array' => ['{"all": [{}, true]}', '{}', false],
    'none on empty' => ['{"none": [[], true]}', '{}', true],
    'none all falsy' => ['{"none": [[0, ""], {"var": ""}]}', '{}', true],
    'none with a truthy item' => ['{"none": [[0, 1], {"var": ""}]}', '{}', false],
    'some on empty' => ['{"some": [[], true]}', '{}', false],
    'some with a truthy item' => ['{"some": [[0, 1], {"var": ""}]}', '{}', true],
    'some all falsy' => ['{"some": [[0, ""], {"var": ""}]}', '{}', false],
    'some on non-array' => ['{"some": [7, true]}', '{}', false],
    'reduce with initial' => ['{"reduce": [[1, 2, 3], {"+": [{"var": "current"}, {"var": "accumulator"}]}, 10]}', '{}', 16.0],
    'reduce without initial' => ['{"reduce": [[1, 2, 3], {"+": [{"var": "current"}, {"var": "accumulator"}]}]}', '{}', 6.0],
    'reduce strings without initial' => ['{"reduce": [["a", "b"], {"cat": [{"var": "accumulator"}, {"var": "current"}]}]}', '{}', 'ab'],
    'reduce keeps last current' => ['{"reduce": [[1, 2, 3], {"var": "current"}]}', '{}', 3],
    'reduce over non-array' => ['{"reduce": [5, {"var": "current"}, "init"]}', '{}', 'init'],
    'reduce over non-array without initial' => ['{"reduce": [5, {"var": "current"}]}', '{}', null],
    'reduce over empty' => ['{"reduce": [[], {"var": "current"}, 0]}', '{}', 0],
    'reduce initial uses outer scope' => [
        '{"reduce": [{"var": "xs"}, {"+": [{"var": "accumulator"}, {"var": "current"}]}, {"var": "start"}]}',
        '{"xs": [1, 2], "start": 5}',
        8.0,
    ],
]);

test('iteration over a PHP associative array uses its values', function (): void {
    $logic = fs_tree(['map' => [['var' => 'rows'], ['var' => 'n']]]);
    $data = ['rows' => ['x' => fs_tree(['n' => 1]), 'y' => fs_tree(['n' => 2])]];

    expect((new JsonLogic)->evaluate($logic, $data))->toBe([1, 2]);
});

test('filter returns the matching items themselves', function (): void {
    $data = fs_tree(['items' => [['ok' => true], ['ok' => false]]]);
    $result = (new JsonLogic)->evaluate(fs_tree(['filter' => [['var' => 'items'], ['var' => 'ok']]]), $data);

    expect($result)->toHaveCount(1)
        ->and($result[0])->toBe($data->items[0]);
});
