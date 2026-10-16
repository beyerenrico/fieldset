<?php

declare(strict_types=1);

use FormSchema\Json\JsNumber;

require_once __DIR__.'/../Support/helpers.php';

test('toString formats numbers like JavaScript String(n)', function (int|float $number, string $expected): void {
    expect(JsNumber::toString($number))->toBe($expected);
})->with([
    'int' => [42, '42'],
    'negative int' => [-7, '-7'],
    'int zero' => [0, '0'],
    'int 2^53' => [9007199254740992, '9007199254740992'],
    'int beyond 2^53 printed via the double' => [PHP_INT_MAX, '9223372036854776000'],
    'int 2^53 + 1 rounds to 2^53' => [9007199254740993, '9007199254740992'],
    'int -2^53 - 1 rounds to -2^53' => [-9007199254740993, '-9007199254740992'],
    'integral float' => [100.0, '100'],
    'integral float without trailing zeros' => [123.0, '123'],
    'zero' => [0.0, '0'],
    'negative zero' => [-0.0, '0'],
    'fraction' => [123.456, '123.456'],
    'half' => [0.5, '0.5'],
    'negative fraction' => [-0.5, '-0.5'],
    'shortest round trip' => [0.1 + 0.2, '0.30000000000000004'],
    '1e20 written out' => [1e20, '100000000000000000000'],
    'largest 21-digit value' => [999999999999999900000.0, '999999999999999900000'],
    '1e21 exponent' => [1e21, '1e+21'],
    '-1e21 exponent' => [-1e21, '-1e+21'],
    'exponent with fraction' => [1.234e25, '1.234e+25'],
    'max double' => [1.7976931348623157e308, '1.7976931348623157e+308'],
    '0.000001 plain' => [0.000001, '0.000001'],
    '0.0000025 plain' => [2.5e-6, '0.0000025'],
    '1e-7 exponent' => [1e-7, '1e-7'],
    '-1e-7 exponent' => [-1e-7, '-1e-7'],
    '1.5e-7' => [1.5e-7, '1.5e-7'],
    'small exponent' => [1.23e-18, '1.23e-18'],
    'min subnormal' => [5e-324, '5e-324'],
    '2^53' => [9007199254740992.0, '9007199254740992'],
    'NaN' => [NAN, 'NaN'],
    'Infinity' => [INF, 'Infinity'],
    '-Infinity' => [-INF, '-Infinity'],
]);

test('toString restores serialize_precision', function (): void {
    $before = ini_get('serialize_precision');
    JsNumber::toString(0.1);

    expect(ini_get('serialize_precision'))->toBe($before);
});

test('isSafeInteger accepts integral values within ±2^53', function (mixed $value, bool $expected): void {
    expect(JsNumber::isSafeInteger($value))->toBe($expected);
})->with([
    'zero' => [0, true],
    '2^53' => [9007199254740992, true],
    '-2^53' => [-9007199254740992, true],
    '2^53 + 1 rounds to 2^53' => [9007199254740993, true],
    '2^53 + 2' => [9007199254740994, false],
    'PHP_INT_MIN' => [PHP_INT_MIN, false],
    'integral float' => [2.0, true],
    'negative integral float' => [-2.0, true],
    'float 2^53' => [9007199254740992.0, true],
    'float 2^54' => [18014398509481984.0, false],
    'fractional float' => [2.5, false],
    'Infinity' => [INF, false],
    'NaN' => [NAN, false],
    'numeric string' => ['2', false],
    'null' => [null, false],
    'bool' => [true, false],
]);

test('safeInteger returns the integer a double holds', function (mixed $value, ?int $expected): void {
    expect(JsNumber::safeInteger($value))->toBe($expected);
})->with([
    'int' => [7, 7],
    'integral float' => [2.0, 2],
    'negative zero' => [-0.0, 0],
    '2^53 + 1 rounds to 2^53' => [9007199254740993, 9007199254740992],
    '-2^53 - 1 rounds to -2^53' => [-9007199254740993, -9007199254740992],
    '2^53 + 2' => [9007199254740994, null],
    'fractional float' => [2.5, null],
    'Infinity' => [INF, null],
    'NaN' => [NAN, null],
    'numeric string' => ['2', null],
    'null' => [null, null],
]);

test('MAX_SAFE_INTEGER is 2^53', function (): void {
    expect(JsNumber::MAX_SAFE_INTEGER)->toBe(2 ** 53);
});

test('fromString implements Number(string)', function (string $value, float $expected): void {
    expect(JsNumber::fromString($value))->toBe($expected);
})->with([
    'empty' => ['', 0.0],
    'spaces' => ['   ', 0.0],
    'ascii whitespace' => ["\t\n\r\v\f", 0.0],
    'no-break spaces' => ["\u{00A0}12\u{00A0}", 12.0],
    'byte order mark' => ["\u{FEFF}7", 7.0],
    'line separator and ideographic space' => ["\u{2028}3\u{3000}", 3.0],
    'padded' => ['  42  ', 42.0],
    'negative' => ['-1.5', -1.5],
    'plus sign' => ['+2', 2.0],
    'leading dot' => ['.5', 0.5],
    'trailing dot' => ['5.', 5.0],
    'exponent' => ['1e3', 1000.0],
    'negative exponent' => ['1E-2', 0.01],
    'signed dot exponent' => ['-.5e1', -5.0],
    'leading zeros' => ['00012', 12.0],
    'overflow' => ['1e1000', INF],
    'Infinity' => ['Infinity', INF],
    '+Infinity' => ['+Infinity', INF],
    '-Infinity' => ['-Infinity', -INF],
    'hex' => ['0x1F', 31.0],
    'hex upper prefix' => ['0X1f', 31.0],
    'padded hex' => [' 0x10 ', 16.0],
    'octal' => ['0o17', 15.0],
    'octal upper prefix' => ['0O17', 15.0],
    'binary' => ['0b101', 5.0],
    'binary upper prefix' => ['0B11', 3.0],
]);

test('fromString keeps negative zero', function (): void {
    expect(fdiv(1.0, JsNumber::fromString('-0')))->toBe(-INF);
});

test('fromString returns NaN for unparsable strings', function (string $value): void {
    expect(JsNumber::fromString($value))->toBeNan();
})->with([
    'letters' => ['abc'],
    'lowercase infinity' => ['infinity'],
    'dangling exponent' => ['1e'],
    'lone dot' => ['.'],
    'inner space' => ['1 2'],
    'signed hex' => ['-0x10'],
    'empty hex' => ['0x'],
    'invalid octal digit' => ['0o8'],
    'invalid binary digit' => ['0b2'],
    'numeric separator' => ['1_000'],
    'zero-width space is not whitespace' => ["\u{200B}1"],
    'unit suffix' => ['12px'],
    'invalid UTF-8' => ["\xff"],
]);
