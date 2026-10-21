<?php

declare(strict_types=1);

use Fieldset\Json\Json;
use Fieldset\Logic\Js;
use Fieldset\Logic\JsonLogic;

require_once __DIR__.'/../Support/helpers.php';

describe('Js', function (): void {
    test('truthy follows spec §4.1', function (mixed $value, bool $expected): void {
        expect(Js::truthy($value))->toBe($expected);
    })->with([
        'null' => [null, false],
        'false' => [false, false],
        'empty string' => ['', false],
        'empty array' => [[], false],
        'int zero' => [0, false],
        'float zero' => [0.0, false],
        'negative zero' => [-0.0, false],
        'NaN' => [NAN, false],
        'true' => [true, true],
        'one' => [1, true],
        'negative' => [-1, true],
        'fraction' => [0.1, true],
        'Infinity' => [INF, true],
        'string zero' => ['0', true],
        'space' => [' ', true],
        'string false' => ['false', true],
        'array with zero' => [[0], true],
        'nested empty array' => [[[]], true],
        'empty object' => [new stdClass, true],
    ]);

    test('looseEquals implements abstract equality for JSON values', function (mixed $a, mixed $b, bool $expected): void {
        expect(Js::looseEquals($a, $b))->toBe($expected);
    })->with([
        'null == null' => [null, null, true],
        'null == 0' => [null, 0, false],
        '0 == null' => [0, null, false],
        'null == ""' => [null, '', false],
        'null == false' => [null, false, false],
        '[] == null' => [[], null, false],
        '{} == {}' => [new stdClass, new stdClass, false],
        '{} == "[object Object]"' => [new stdClass, '[object Object]', false],
        '"[object Object]" == {}' => ['[object Object]', new stdClass, false],
        '[{}] == "[object Object]"' => [[new stdClass], '[object Object]', true],
        '[] == []' => [[], [], true],
        '[1,2] == [1,2]' => [[1, 2], [1, 2], true],
        '[1,2] == [2,1]' => [[1, 2], [2, 1], false],
        '[1] == ["1"]' => [[1], ['1'], true],
        '[null] == []' => [[null], [], true],
        'true == 1' => [true, 1, true],
        'true == "1"' => [true, '1', true],
        'true == "true"' => [true, 'true', false],
        'false == 0' => [false, 0, true],
        'false == ""' => [false, '', true],
        'false == "0"' => [false, '0', true],
        'true == 2' => [true, 2, false],
        'true == [1]' => [true, [1], true],
        'false == []' => [false, [], true],
        '[0] == false' => [[0], false, true],
        'true == true' => [true, true, true],
        'true == false' => [true, false, false],
        'true == null' => [true, null, false],
        '[1,2] == "1,2"' => [[1, 2], '1,2', true],
        '"1,2" == [1,2]' => ['1,2', [1, 2], true],
        '[] == ""' => [[], '', true],
        '[] == 0' => [[], 0, true],
        '[5] == 5' => [[5], 5, true],
        '[1,2] == 1' => [[1, 2], 1, false],
        'nested array == joined string' => [[[1, 2], 3], '1,2,3', true],
        '"a" == "a"' => ['a', 'a', true],
        '"1" == "01"' => ['1', '01', false],
        '"" == "0"' => ['', '0', false],
        '1 == "1"' => [1, '1', true],
        '1 == "1.0"' => [1, '1.0', true],
        '"1.0" == 1' => ['1.0', 1, true],
        '0 == ""' => [0, '', true],
        '0 == " "' => [0, ' ', true],
        '1 == "abc"' => [1, 'abc', false],
        '1 == 1.0' => [1, 1.0, true],
        '0.1 + 0.2 == 0.3' => [0.1 + 0.2, 0.3, false],
        '16 == "0x10"' => [16, '0x10', true],
        'Infinity == "Infinity"' => [INF, 'Infinity', true],
        'NaN == NaN' => [NAN, NAN, false],
    ]);

    test('strictEquals compares type and value', function (mixed $a, mixed $b, bool $expected): void {
        expect(Js::strictEquals($a, $b))->toBe($expected);
    })->with([
        'int and float' => [1, 1.0, true],
        'same int' => [1, 1, true],
        'different numbers' => [1, 2, false],
        'string and number' => ['1', 1, false],
        'number and string' => [1, '1', false],
        'same string' => ['a', 'a', true],
        'different strings' => ['a', 'b', false],
        'null' => [null, null, true],
        'null and false' => [null, false, false],
        'booleans' => [true, true, true],
        'bool and number' => [true, 1, false],
        'empty arrays' => [[], [], false],
        'equal arrays' => [[1], [1], false],
        'objects' => [new stdClass, new stdClass, false],
        'array and string' => [[1], '1', false],
        'NaN' => [NAN, NAN, false],
        'signed zeros' => [0.0, -0.0, true],
    ]);

    test('strictEquals never treats the same object instance as equal', function (): void {
        $object = new stdClass;

        expect(Js::strictEquals($object, $object))->toBeFalse()
            ->and(Js::looseEquals($object, $object))->toBeFalse();
    });

    test('compare orders strings by code point and everything else as numbers', function (mixed $a, mixed $b, ?int $expected): void {
        expect(Js::compare($a, $b))->toBe($expected);
    })->with([
        'a < b' => ['a', 'b', -1],
        'b > a' => ['b', 'a', 1],
        'equal strings' => ['a', 'a', 0],
        'uppercase first' => ['Z', 'a', -1],
        'non-ASCII after ASCII' => ['z', 'é', -1],
        'numeric strings as strings' => ['10', '9', -1],
        'code points, not UTF-16 units' => ["\u{FFFD}", "\u{1F600}", -1],
        'prefix is smaller' => ['abc', 'ab', 1],
        'empty string first' => ['', 'a', -1],
        'numbers' => [1, 2, -1],
        'numbers reversed' => [2, 1, 1],
        'int and float' => [2, 2.0, 0],
        'string and number' => ['10', 9, 1],
        'number and string' => [9, '10', -1],
        'null is zero' => [null, 0, 0],
        'null above negative' => [null, -1, 1],
        'true is one' => [true, 1, 0],
        'false below half' => [false, 0.5, -1],
        'padded numeric string' => [' 12 ', 12, 0],
        'empty string is zero' => ['', 0, 0],
        'Infinity' => [INF, 1e308, 1],
        'unparsable string' => ['abc', 1, null],
        'unparsable second operand' => [1, 'abc', null],
        'empty array is NaN' => [[], 0, null],
        'array is NaN' => [[1], 1, null],
        'object is NaN' => [new stdClass, 1, null],
        'NaN' => [NAN, 1, null],
        'NaN second operand' => [1, NAN, null],
    ]);

    test('toNumber converts null, booleans, numbers and strings', function (mixed $value, float $expected): void {
        expect(Js::toNumber($value))->toBe($expected);
    })->with([
        'null' => [null, 0.0],
        'true' => [true, 1.0],
        'false' => [false, 0.0],
        'int' => [5, 5.0],
        'float' => [2.5, 2.5],
        'numeric string' => ['12', 12.0],
        'empty string' => ['', 0.0],
        'hex string' => [' 0x10 ', 16.0],
    ]);

    test('toNumber returns NaN for unparsable strings, arrays and objects', function (mixed $value): void {
        expect(Js::toNumber($value))->toBeNan();
    })->with([
        'letters' => ['abc'],
        'empty array' => [[]],
        'single item array' => [[7]],
        'object' => [new stdClass],
    ]);

    test('toJsString implements String(value)', function (mixed $value, string $expected): void {
        expect(Js::toJsString($value))->toBe($expected);
    })->with([
        'null' => [null, 'null'],
        'true' => [true, 'true'],
        'false' => [false, 'false'],
        'int' => [5, '5'],
        'integral float' => [2.0, '2'],
        'fraction' => [1.5, '1.5'],
        'large float' => [1e21, '1e+21'],
        'NaN' => [NAN, 'NaN'],
        'string' => ['x', 'x'],
        'empty array' => [[], ''],
        'mixed array' => [[1, null, [2, 3], true, 'a'], '1,,2,3,true,a'],
        'array of objects' => [[new stdClass], '[object Object]'],
        'object' => [new stdClass, '[object Object]'],
    ]);

    test('catString turns null into an empty string', function (mixed $value, string $expected): void {
        expect(Js::catString($value))->toBe($expected);
    })->with([
        'null' => [null, ''],
        'integral float' => [1.0, '1'],
        'bool' => [true, 'true'],
        'array' => [[1, 2], '1,2'],
        'string' => ['a', 'a'],
    ]);

    test('isNumber is true for ints and floats only', function (mixed $value, bool $expected): void {
        expect(Js::isNumber($value))->toBe($expected);
    })->with([
        'int' => [1, true],
        'float' => [1.5, true],
        'NaN' => [NAN, true],
        'numeric string' => ['1', false],
        'null' => [null, false],
        'bool' => [true, false],
    ]);
});

describe('comparison operators', function (): void {
    test('evaluate', function (string $logic, bool $expected): void {
        expect((new JsonLogic)->evaluate(Json::decode($logic), new stdClass))->toBe($expected);
    })->with([
        ['{"==": [1, "1"]}', true],
        ['{"==": [null, null]}', true],
        ['{"==": [null]}', false],
        ['{"==": []}', false],
        ['{"==": [[1, 2], "1,2"]}', true],
        ['{"==": [[1, 2], [1, 2]]}', true],
        ['{"==": [{}, {}]}', false],
        ['{"!=": [1, 2]}', true],
        ['{"!=": [1, "1"]}', false],
        ['{"!=": [null]}', true],
        ['{"!=": []}', true],
        ['{"===": [1, 1.0]}', true],
        ['{"===": [1, "1"]}', false],
        ['{"===": [null]}', false],
        ['{"===": [[], []]}', false],
        ['{"!==": [1]}', true],
        ['{"!==": [1, 2]}', true],
        ['{"!==": ["a", "a"]}', false],
        ['{"<": [1, 2]}', true],
        ['{"<": [2, 1]}', false],
        ['{"<": [1]}', false],
        ['{"<": [1, 2, 3]}', true],
        ['{"<": [1, 3, 2]}', false],
        ['{"<": [2, 1, 3]}', false],
        ['{"<": [1, 2, "x"]}', false],
        ['{"<=": [1, 1, 1]}', true],
        ['{"<=": [1, 2, 1]}', false],
        ['{"<=": [2, 2]}', true],
        ['{">": [3, 2, 5]}', true],
        ['{">": [2, 2]}', false],
        ['{">": [2]}', false],
        ['{">=": [2, 2]}', true],
        ['{">=": [2, 3, 1]}', false],
        ['{">=": [3, 2, 9]}', true],
        ['{"<": ["a", "b"]}', true],
        ['{"<": ["10", "9"]}', true],
        ['{"<": ["10", 9]}', false],
        ['{">": ["abc", 1]}', false],
        ['{"<": ["abc", 1]}', false],
        ['{"<": [null, 1]}', true],
        ['{"<": [false, true]}', true],
        ['{"<": [[], 1]}', false],
    ]);
});

describe('arithmetic operators', function (): void {
    test('evaluate on doubles', function (string $logic, ?float $expected): void {
        expect((new JsonLogic)->evaluate(Json::decode($logic), new stdClass))->toBe($expected);
    })->with([
        ['{"+": []}', 0.0],
        ['{"+": [1, 2]}', 3.0],
        ['{"+": ["1", 2.5]}', 3.5],
        ['{"+": ["3"]}', 3.0],
        ['{"+": [null, 1]}', 1.0],
        ['{"+": [true, true]}', 2.0],
        ['{"+": [" 4 ", "0x10"]}', 20.0],
        ['{"+": ["1.5abc", 1]}', null],
        ['{"+": [[1], 1]}', null],
        ['{"+": [{}, 1]}', null],
        ['{"+": [1e308, 1e308]}', null],
        ['{"*": []}', null],
        ['{"*": ["2"]}', 2.0],
        ['{"*": 5}', 5.0],
        ['{"*": [2, "3"]}', 6.0],
        ['{"*": [2, 3, 4]}', 24.0],
        ['{"*": ["2x", 3]}', null],
        ['{"*": [null, 2]}', 0.0],
        ['{"*": [1e200, 1e200]}', null],
        ['{"-": []}', null],
        ['{"-": [5]}', -5.0],
        ['{"-": ["5"]}', -5.0],
        ['{"-": [5, "2"]}', 3.0],
        ['{"-": [5, 2, 1]}', 3.0],
        ['{"-": [true, null]}', 1.0],
        ['{"-": ["a", 1]}', null],
        ['{"-": [[2], 1]}', null],
        ['{"-": [-1e308, 1e308]}', null],
        ['{"/": [6, "3"]}', 2.0],
        ['{"/": [1, 4]}', 0.25],
        ['{"/": [1, 0]}', null],
        ['{"/": [-1, 0]}', null],
        ['{"/": [0, 0]}', null],
        ['{"/": []}', null],
        ['{"/": [1]}', null],
        ['{"%": [7, 3]}', 1.0],
        ['{"%": [-7, 3]}', -1.0],
        ['{"%": [7.5, 2]}', 1.5],
        ['{"%": ["8", "3"]}', 2.0],
        ['{"%": [7, 0]}', null],
        ['{"%": []}', null],
        ['{"%": [7]}', null],
    ]);

    test('normalizes negative zero to zero', function (string $logic): void {
        $result = (new JsonLogic)->evaluate(Json::decode($logic), new stdClass);

        expect($result)->toBe(0.0)
            ->and(fdiv(1.0, $result))->toBe(INF);
    })->with([
        'negated zero' => ['{"-": [0]}'],
        'negative product' => ['{"*": [-1, 0]}'],
        'negative quotient' => ['{"/": [0, -5]}'],
        'negative remainder' => ['{"%": [-6, 3]}'],
        'negative zero sum' => ['{"+": [-0.0]}'],
    ]);
});
