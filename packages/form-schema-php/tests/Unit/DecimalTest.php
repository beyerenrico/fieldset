<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use FormSchema\Json\Json;
use FormSchema\Logic\Decimal;
use FormSchema\Logic\JsonLogic;

require_once __DIR__.'/../Support/helpers.php';

describe('Decimal', function (): void {
    test('isDecimalString matches the §5.1 pattern', function (mixed $value, bool $expected): void {
        expect(Decimal::isDecimalString($value))->toBe($expected);
    })->with([
        ['0', true], ['-0', true], ['12', true], ['-12.50', true], ['0.001', true],
        ['01', false], ['+1', false], ['1.', false], ['.5', false], ['1e3', false], [' 1', false],
        ['1,5', false], ['', false], ['-', false], ["1\n", false], [12, false], [null, false],
    ]);

    test('parse accepts decimal strings and JSON integers up to 2^53', function (mixed $value, ?string $expected): void {
        $parsed = Decimal::parse($value);

        expect($parsed === null ? null : Decimal::format($parsed))->toBe($expected);
    })->with([
        'decimal string keeps scale' => ['1.50', '1.50'],
        'negative zero string' => ['-0.00', '0.00'],
        'minus zero' => ['-0', '0'],
        'int' => [5, '5'],
        'integral float' => [2.0, '2'],
        'negative integral float' => [-2.0, '-2'],
        '2^53' => [9007199254740992, '9007199254740992'],
        '-2^53' => [-9007199254740992, '-9007199254740992'],
        'float 2^53' => [9007199254740992.0, '9007199254740992'],
        'int 2^53 + 1 rounds to 2^53' => [9007199254740993, '9007199254740992'],
        'int beyond the double range of 2^53' => [9007199254740994, null],
        'float beyond 2^53' => [18014398509481984.0, null],
        'fractional float' => [1.5, null],
        'Infinity' => [INF, null],
        'NaN' => [NAN, null],
        'exponent string' => ['1e3', null],
        'bool' => [true, null],
        'null' => [null, null],
        'array' => [[1], null],
        'object' => [new stdClass, null],
    ]);

    test('fromConstraint accepts JSON numbers and decimal strings', function (mixed $value, ?string $expected): void {
        $parsed = Decimal::fromConstraint($value);

        expect($parsed === null ? null : Decimal::format($parsed))->toBe($expected);
    })->with([
        'float' => [0.1, '0.1'],
        'integral float' => [1.0, '1'],
        'large float' => [1e21, '1000000000000000000000'],
        'small float' => [1e-7, '0.0000001'],
        'int' => [5, '5'],
        'decimal string' => ['2.50', '2.50'],
        'Infinity' => [INF, null],
        'NaN' => [NAN, null],
        'invalid string' => ['x', null],
        'bool' => [true, null],
        'null' => [null, null],
    ]);

    test('format writes the canonical form', function (): void {
        expect(Decimal::format(BigDecimal::of('-0.00')))->toBe('0.00')
            ->and(Decimal::format(BigDecimal::of('1.230')))->toBe('1.230')
            ->and(Decimal::format(BigDecimal::of('-12')))->toBe('-12');
    });

    test('toScale rounds half-up away from zero', function (string $value, int $scale, string $expected): void {
        expect(Decimal::format(Decimal::toScale(BigDecimal::of($value), $scale)))->toBe($expected);
    })->with([
        ['2.345', 2, '2.35'],
        ['-2.345', 2, '-2.35'],
        ['2.5', 0, '3'],
        ['-2.5', 0, '-3'],
        ['2.4', 0, '2'],
        ['2', 3, '2.000'],
        ['-0.004', 2, '0.00'],
        ['1.005', 2, '1.01'],
    ]);

    test('scaleOperand accepts JSON integers from 0 to the maximum', function (mixed $value, int $max, ?int $expected): void {
        expect(Decimal::scaleOperand($value, $max))->toBe($expected);
    })->with([
        'zero' => [0, 20, 0],
        'int' => [2, 20, 2],
        'maximum' => [20, 20, 20],
        'above maximum' => [21, 20, null],
        'integral float' => [2.0, 20, 2],
        'negative zero' => [-0.0, 20, 0],
        'fractional float' => [2.5, 20, null],
        'negative' => [-1, 20, null],
        '2^53 + 1 rounds to 2^53' => [9007199254740993, PHP_INT_MAX, 9007199254740992],
        'beyond 2^53' => [9007199254740994, PHP_INT_MAX, null],
        'Infinity' => [INF, 20, null],
        'integer string' => ['2', 20, null],
        'null' => [null, 20, null],
        'bool' => [true, 20, null],
        'custom maximum exceeded' => [5, 3, null],
        'custom maximum' => [3, 3, 3],
    ]);

    test('scaleOperand defaults to MAX_DIVISION_SCALE', function (): void {
        expect(Decimal::MAX_DIVISION_SCALE)->toBe(20)
            ->and(Decimal::scaleOperand(20))->toBe(20)
            ->and(Decimal::scaleOperand(21))->toBeNull();
    });

    test('fractionDigits counts digits after the point', function (string $value, int $expected): void {
        expect(Decimal::fractionDigits($value))->toBe($expected);
    })->with([['12', 0], ['1.250', 3], ['-0.5', 1], ['0', 0]]);

    test('parseAll returns all operands or null', function (): void {
        $parsed = Decimal::parseAll(['1.5', 2, 3.0]);

        expect(array_map(Decimal::format(...), $parsed ?? []))->toBe(['1.5', '2', '3'])
            ->and(Decimal::parseAll([]))->toBeNull()
            ->and(Decimal::parseAll(['1', 'x']))->toBeNull()
            ->and(Decimal::parseAll([null]))->toBeNull();
    });

    test('compare is numeric and null when an operand counts as null', function (mixed $a, mixed $b, ?int $expected): void {
        expect(Decimal::compare($a, $b))->toBe($expected);
    })->with([
        ['1.0', '1', 0],
        ['2', '1.99', 1],
        ['-1', 0, -1],
        ['x', '1', null],
        ['1', null, null],
        [1.5, 1, null],
    ]);
});

test('decimal operators', function (string $logic, mixed $expected): void {
    expect((new JsonLogic)->evaluate(Json::decode($logic), new stdClass))->toBe($expected);
})->with([
    'add keeps largest scale' => ['{"dec.add": ["1.10", "2.205"]}', '3.305'],
    'add with int' => ['{"dec.add": ["1.10", 2]}', '3.10'],
    'add with integral float' => ['{"dec.add": ["1.5", 2.0]}', '3.5'],
    'add many' => ['{"dec.add": ["1", "2", "3.000"]}', '6.000'],
    'add single operand' => ['{"dec.add": ["1"]}', '1'],
    'add normalizes negative zero' => ['{"dec.add": ["-0.00"]}', '0.00'],
    'add to zero keeps scale' => ['{"dec.add": ["0.10", "-0.10"]}', '0.00'],
    'add beyond 2^53 as strings' => ['{"dec.add": ["9007199254740992", 1]}', '9007199254740993'],
    'add two 2^53 ints' => ['{"dec.add": [9007199254740992, 9007199254740992]}', '18014398509481984'],
    'add without operands' => ['{"dec.add": []}', null],
    'add with null' => ['{"dec.add": ["1", null]}', null],
    'add with fractional float' => ['{"dec.add": ["1", 1.5]}', null],
    'add with bool' => ['{"dec.add": ["1", true]}', null],
    'add with array' => ['{"dec.add": ["1", [1]]}', null],
    'add with object' => ['{"dec.add": ["1", {}]}', null],
    'add with exponent string' => ['{"dec.add": ["1", "1e2"]}', null],
    'add with int 2^53 + 1 as a double' => ['{"dec.add": [9007199254740993, 0]}', '9007199254740992'],
    'add with int beyond 2^53' => ['{"dec.add": ["1", 9007199254740994]}', null],
    'sub' => ['{"dec.sub": ["5", "1.25", "0.5"]}', '3.25'],
    'sub to zero' => ['{"dec.sub": ["-0.5", "-0.5"]}', '0.0'],
    'sub keeps largest scale' => ['{"dec.sub": ["1.0", "1.000"]}', '0.000'],
    'sub with null' => ['{"dec.sub": ["1", null]}', null],
    'mul sums scales' => ['{"dec.mul": ["1.5", "2.25"]}', '3.375'],
    'mul keeps trailing zeros' => ['{"dec.mul": ["1.10", "2.0"]}', '2.200'],
    'mul negative zero' => ['{"dec.mul": ["-0.5", "0"]}', '0.0'],
    'mul many' => ['{"dec.mul": ["2", 3, "0.5"]}', '3.0'],
    'mul invalid' => ['{"dec.mul": ["x", 2]}', null],
    'div' => ['{"dec.div": ["10", "3", 2]}', '3.33'],
    'div rounds up' => ['{"dec.div": ["2", "3", 0]}', '1'],
    'div rounds away from zero' => ['{"dec.div": ["-2", "3", 0]}', '-1'],
    'div half-up' => ['{"dec.div": ["1", "8", 2]}', '0.13'],
    'div negative half-up' => ['{"dec.div": ["-1", "8", 2]}', '-0.13'],
    'div exact half' => ['{"dec.div": ["10", 4, 0]}', '3'],
    'div negative exact half' => ['{"dec.div": ["-10", 4, 0]}', '-3'],
    'div maximum scale' => ['{"dec.div": ["1", "3", 20]}', '0.33333333333333333333'],
    'div integral float scale' => ['{"dec.div": ["1", "4", 2.0]}', '0.25'],
    'div pads scale' => ['{"dec.div": ["7", "2", 3]}', '3.500'],
    'div rounds to zero' => ['{"dec.div": ["-0.001", "1", 2]}', '0.00'],
    'div zero dividend' => ['{"dec.div": ["0", "3", 2]}', '0.00'],
    'div by zero' => ['{"dec.div": ["1", "0", 2]}', null],
    'div by zero with scale' => ['{"dec.div": ["1", "0.00", 2]}', null],
    'div scale above 20' => ['{"dec.div": ["1", "3", 21]}', null],
    'div negative scale' => ['{"dec.div": ["1", "3", -1]}', null],
    'div fractional scale' => ['{"dec.div": ["1", "3", 2.5]}', null],
    'div string scale' => ['{"dec.div": ["1", "4", "2"]}', null],
    'div missing scale' => ['{"dec.div": ["1", "3"]}', null],
    'div null scale' => ['{"dec.div": ["1", "3", null]}', null],
    'div null dividend' => ['{"dec.div": [null, "3", 2]}', null],
    'div null divisor' => ['{"dec.div": ["1", null, 2]}', null],
    'round' => ['{"dec.round": ["2.345", 2]}', '2.35'],
    'round negative' => ['{"dec.round": ["-2.345", 2]}', '-2.35'],
    'round half' => ['{"dec.round": ["2.5", 0]}', '3'],
    'round negative half' => ['{"dec.round": ["-2.5", 0]}', '-3'],
    'round pads' => ['{"dec.round": ["2", 2]}', '2.00'],
    'round negative to zero' => ['{"dec.round": ["-0.004", 2]}', '0.00'],
    'round int' => ['{"dec.round": [7, 1]}', '7.0'],
    'round maximum scale' => ['{"dec.round": ["1.23456", 20]}', '1.23456000000000000000'],
    'round missing scale' => ['{"dec.round": ["2.5"]}', null],
    'round null scale' => ['{"dec.round": ["2.5", null]}', null],
    'round fractional scale' => ['{"dec.round": ["2.5", 1.5]}', null],
    'round negative scale' => ['{"dec.round": ["2.5", -1]}', null],
    'round string scale' => ['{"dec.round": ["2.5", "1"]}', null],
    'round invalid value' => ['{"dec.round": ["x", 2]}', null],
]);

test('decimal comparisons', function (string $operator, mixed $a, mixed $b, bool $expected): void {
    expect((new JsonLogic)->evaluate((object) [$operator => [$a, $b]], new stdClass))->toBe($expected);
})->with([
    ['dec.eq', '1.0', '1', true],
    ['dec.eq', '1', '2', false],
    ['dec.gt', '2', '1.99', true],
    ['dec.gt', '1', '1', false],
    ['dec.gte', '1', '1.00', true],
    ['dec.gte', '0.9', '1', false],
    ['dec.lt', '-1', 0, true],
    ['dec.lt', 1, 1, false],
    ['dec.lte', 1, '1.0', true],
    ['dec.lte', '2', '1', false],
    ['dec.eq', null, null, false],
    ['dec.gt', '1', null, false],
    ['dec.gte', 'x', '1', false],
    ['dec.lt', 1.5, 2, false],
    ['dec.lte', '1', true, false],
]);

test('decimal comparison with a missing operand is false', function (): void {
    expect((new JsonLogic)->evaluate(fs_tree(['dec.lt' => ['1']]), null))->toBeFalse();
});

test('aggregate operators', function (string $logic, string $data, mixed $expected): void {
    expect((new JsonLogic)->evaluate(Json::decode($logic), Json::decode($data)))->toBe($expected);
})->with([
    'sum skips null-like items' => ['{"sum": [[1, "2.50", null, "x", 1.5, true]]}', '{}', '3.50'],
    'sum of empty array' => ['{"sum": [[]]}', '{}', '0'],
    'sum of non-array' => ['{"sum": ["abc"]}', '{}', '0'],
    'sum without operands' => ['{"sum": []}', '{}', '0'],
    'sum of null' => ['{"sum": [null]}', '{}', '0'],
    'sum keeps largest scale' => ['{"sum": [["-1.5", "1.5"]]}', '{}', '0.0'],
    'sum by row key' => ['{"sum": [{"var": "r"}, "a"]}', '{"r": [{"a": "1.5"}, {"a": 2}, {"b": 3}, {"a": null}]}', '3.5'],
    'sum by row key skips non-objects' => ['{"sum": [{"var": "r"}, "a"]}', '{"r": [1, [2], "x", {"a": "1"}]}', '1'],
    'sum ignores non-string row key' => ['{"sum": [[1, 2], 1]}', '{}', '3'],
    'sum ignores null row key' => ['{"sum": [[1, 2], null]}', '{}', '3'],
    'sum of rows' => ['{"sum": [{"var": "rows"}, "amount"]}', '{"rows": [{"amount": "10.00"}, {"amount": "5.5"}]}', '15.50'],
    'min of array' => ['{"min": [[3, "1.5", 2]]}', '{}', '1.5'],
    'max of array' => ['{"max": [[3, "1.5", 2]]}', '{}', '3'],
    'min variadic' => ['{"min": [3, "1.5", "-2"]}', '{}', '-2'],
    'max variadic skips nulls' => ['{"max": [3, "1.50", "x", null]}', '{}', '3'],
    'min of empty array' => ['{"min": [[]]}', '{}', null],
    'max without operands' => ['{"max": []}', '{}', null],
    'max of a single non-array operand' => ['{"max": [5]}', '{}', null],
    'max of a single wrapped operand' => ['{"max": [[5]]}', '{}', '5'],
    'min of null-like operands' => ['{"min": ["x", null, 1.5]}', '{}', null],
    'max by row key' => ['{"max": [{"var": "r"}, "a"]}', '{"r": [{"a": "5"}, {"a": "7.0"}, {"b": "9"}]}', '7.0'],
    'min by row key skips non-objects' => ['{"min": [{"var": "r"}, "a"]}', '{"r": [{"a": "5"}, {"a": "7"}, 3]}', '5'],
    'max keeps first on tie' => ['{"max": [["1.0", "1"]]}', '{}', '1.0'],
    'min keeps first on tie' => ['{"min": [["1", "1.0"]]}', '{}', '1'],
    'max with array ignores later values' => ['{"max": [[1, 2], 5]}', '{}', '2'],
    'max of rows' => ['{"max": [{"var": "rows"}, "n"]}', '{"rows": [{"n": 1}, {"n": "4.25"}]}', '4.25'],
    'count non-null items' => ['{"count": [[1, null, "a", 0, false]]}', '{}', 4],
    'count of non-array' => ['{"count": ["str"]}', '{}', 0],
    'count without operands' => ['{"count": []}', '{}', 0],
    'count of empty array' => ['{"count": [[]]}', '{}', 0],
    'count of nulls' => ['{"count": [[null]]}', '{}', 0],
    'count of variable' => ['{"count": {"var": "xs"}}', '{"xs": [1, 2]}', 2],
]);

test('aggregates read PHP associative arrays', function (string $operator, string $expected): void {
    $data = [
        'rows' => [['a' => '1.5'], ['b' => '2'], ['a' => 2]],
        'map' => ['x' => '1', 'y' => '2.0'],
    ];

    expect((new JsonLogic)->evaluate(fs_tree([$operator => [['var' => 'rows'], 'a']]), $data))->toBe($expected)
        ->and((new JsonLogic)->evaluate(fs_tree([$operator => [['var' => 'map']]]), $data))
        ->toBe(['sum' => '3.0', 'min' => '1', 'max' => '2.0'][$operator]);
})->with([
    ['sum', '3.5'],
    ['min', '1.5'],
    ['max', '2'],
]);
