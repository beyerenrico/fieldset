<?php

declare(strict_types=1);

use Fieldset\Json\Json;
use Fieldset\Json\JsonPointer;
use Fieldset\Json\JsonWriter;

require_once __DIR__.'/../Support/helpers.php';

describe('Json::decode', function (): void {
    test('keeps objects and arrays apart', function (): void {
        $tree = Json::decode('{"o":{},"a":[],"i":1,"f":1.0,"n":null}');

        expect($tree)->toBeInstanceOf(stdClass::class)
            ->and($tree->o)->toBeInstanceOf(stdClass::class)
            ->and($tree->a)->toBe([])
            ->and($tree->i)->toBe(1)
            ->and($tree->f)->toBe(1.0)
            ->and($tree->n)->toBeNull();
    });

    test('throws on invalid JSON', function (): void {
        expect(fn () => Json::decode('{"a":'))->toThrow(JsonException::class);
    });

    test('accepts exactly MAX_DEPTH levels of objects and arrays', function (): void {
        $arrays = static fn (int $levels): string => str_repeat('[', $levels).'1'.str_repeat(']', $levels);
        $objects = static fn (int $levels): string => str_repeat('{"a":', $levels).'1'.str_repeat('}', $levels);

        expect(Json::MAX_DEPTH)->toBe(1024)
            ->and(Json::decode($arrays(1024)))->toBeArray()
            ->and(Json::decode($objects(1024)))->toBeInstanceOf(stdClass::class)
            ->and(fn () => Json::decode($arrays(1025)))->toThrow(JsonException::class)
            ->and(fn () => Json::decode($objects(1025)))->toThrow(JsonException::class);
    });
});

describe('Json::encode and Json::canonical', function (): void {
    test('encode keeps key order, canonical sorts keys by code point', function (): void {
        $tree = fs_tree(['b' => 1, 'a' => 2, 'é' => 3, 'z' => 4, '10' => 5, '9' => 6]);

        expect(Json::encode($tree))->toBe('{"b":1,"a":2,"é":3,"z":4,"10":5,"9":6}')
            ->and(Json::canonical($tree))->toBe('{"10":5,"9":6,"a":2,"b":1,"z":4,"é":3}');
    });

    test('canonical sorts nested objects inside lists and objects', function (): void {
        $tree = fs_tree(['z' => [['y' => 1, 'x' => ['d' => 1, 'c' => 2]]], 'a' => new stdClass]);

        expect(Json::canonical($tree))->toBe('{"a":{},"z":[{"x":{"c":2,"d":1},"y":1}]}')
            ->and(Json::encode($tree))->toBe('{"z":[{"y":1,"x":{"d":1,"c":2}}],"a":{}}');
    });

    test('writes scalars and numbers in shortest round-trip form', function (mixed $value, string $expected): void {
        expect(Json::encode($value))->toBe($expected)
            ->and(Json::canonical($value))->toBe($expected);
    })->with([
        'null' => [null, 'null'],
        'true' => [true, 'true'],
        'false' => [false, 'false'],
        'int' => [-42, '-42'],
        'int beyond 2^53 via the double' => [PHP_INT_MAX, '9223372036854776000'],
        'int 2^53 + 1 via the double' => [9007199254740993, '9007199254740992'],
        'integral float' => [100.0, '100'],
        'fraction' => [0.5, '0.5'],
        'negative zero' => [-0.0, '0'],
        '1e21' => [1e21, '1e+21'],
        '1e-7' => [1e-7, '1e-7'],
        'NaN becomes null' => [NAN, 'null'],
        'Infinity becomes null' => [INF, 'null'],
        '-Infinity becomes null' => [-INF, 'null'],
        'list of numbers' => [[1, 1.0, 0.1 + 0.2, NAN], '[1,1,0.30000000000000004,null]'],
        'empty list' => [[], '[]'],
        'empty object' => [new stdClass, '{}'],
    ]);

    test('writes strings with slashes, unicode and line terminators unescaped', function (string $value, string $expected): void {
        expect(Json::encode($value))->toBe($expected)
            ->and(JsonWriter::string($value))->toBe($expected);
    })->with([
        'slash' => ['a/b', '"a/b"'],
        'unicode' => ['Grüße 😀', '"Grüße 😀"'],
        'line separators' => ["a\u{2028}b\u{2029}c", "\"a\u{2028}b\u{2029}c\""],
        'control character' => ["a\x01b", '"a\u0001b"'],
        'quote and backslash' => ['say "hi" \\', '"say \"hi\" \\\\"'],
        'newline and tab' => ["a\nb\tc", '"a\nb\tc"'],
        'empty' => ['', '""'],
    ]);

    test('throws on invalid UTF-8', function (): void {
        expect(fn () => Json::encode("\xff"))->toThrow(JsonException::class);
    });
});

describe('JsonWriter', function (): void {
    test('writes associative and non-list arrays as objects', function (): void {
        expect(JsonWriter::write(['b' => 1, 'a' => [true]], false))->toBe('{"b":1,"a":[true]}')
            ->and(JsonWriter::write(['b' => 1, 'a' => [true]], true))->toBe('{"a":[true],"b":1}')
            ->and(JsonWriter::write([1 => 'a', 2 => 'b'], false))->toBe('{"1":"a","2":"b"}')
            ->and(JsonWriter::write([10 => 'x', 9 => 'y', 'a' => 'z'], true))->toBe('{"10":"x","9":"y","a":"z"}')
            ->and(JsonWriter::write(['x', 'y'], true))->toBe('["x","y"]');
    });

    test('writes numeric-string object keys as strings', function (): void {
        $tree = Json::decode('{"2":"b","1":"a"}');

        expect(JsonWriter::write($tree, false))->toBe('{"2":"b","1":"a"}')
            ->and(JsonWriter::write($tree, true))->toBe('{"1":"a","2":"b"}');
    });

    test('writes JsonSerializable values through jsonSerialize()', function (): void {
        $value = new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return ['b' => 1, 'a' => [1.5, INF]];
            }
        };

        expect(JsonWriter::write($value, true))->toBe('{"a":[1.5,null],"b":1}')
            ->and(Json::encode([$value]))->toBe('[{"b":1,"a":[1.5,null]}]');
    });

    test('rejects values that are not JSON-encodable', function (mixed $value, string $type): void {
        expect(fn () => JsonWriter::write($value, false))
            ->toThrow(InvalidArgumentException::class, 'Value is not JSON-encodable: '.$type);
    })->with([
        'object' => [new DateTimeImmutable('2024-01-01'), 'DateTimeImmutable'],
        'nested object' => [[1, ['k' => new ArrayObject]], 'ArrayObject'],
        'closure' => [static fn (): int => 1, 'Closure'],
    ]);
});

describe('Json::checksum', function (): void {
    test('is the lowercase SHA-256 of the canonical JSON', function (): void {
        $checksum = Json::checksum(fs_tree(['b' => 2, 'a' => 1]));

        expect($checksum)->toBe(hash('sha256', '{"a":1,"b":2}'))
            ->and($checksum)->toMatch('/^[0-9a-f]{64}$/')
            ->and(Json::checksum(fs_tree(['a' => 1, 'b' => 2])))->toBe($checksum)
            ->and(Json::checksum(fs_tree(['a' => 1, 'b' => 3])))->not->toBe($checksum);
    });
});

describe('Json::copy', function (): void {
    test('creates a deep, independent copy', function (): void {
        $original = fs_tree(['a' => ['b' => [1, ['c' => 'x']]], 'list' => [['d' => 1]]]);
        $copy = Json::copy($original);

        expect($copy)->toEqual($original)
            ->and($copy)->not->toBe($original)
            ->and($copy->a)->not->toBe($original->a)
            ->and($copy->a->b[1])->not->toBe($original->a->b[1])
            ->and($copy->list[0])->not->toBe($original->list[0]);

        $copy->a->b[1]->c = 'changed';
        $copy->list[0]->d = 2;
        $copy->a->b[] = 'added';

        expect($original->a->b[1]->c)->toBe('x')
            ->and($original->list[0]->d)->toBe(1)
            ->and($original->a->b)->toHaveCount(2);
    });

    test('copies numeric property names and keeps array keys', function (): void {
        $original = Json::decode('{"1":{"x":1}}');
        $copy = Json::copy($original);

        expect(property_exists($copy, '1'))->toBeTrue()
            ->and($copy->{'1'})->not->toBe($original->{'1'})
            ->and(Json::encode($copy))->toBe('{"1":{"x":1}}');

        $assoc = ['k' => $original];
        $assocCopy = Json::copy($assoc);

        expect(array_keys($assocCopy))->toBe(['k'])
            ->and($assocCopy['k'])->not->toBe($original);
    });

    test('returns scalars unchanged', function (mixed $value): void {
        expect(Json::copy($value))->toBe($value);
    })->with([
        'null' => [null],
        'bool' => [true],
        'int' => [0],
        'float' => [1.5],
        'string' => ['text'],
        'empty array' => [[]],
    ]);
});

describe('Json::isList', function (): void {
    test('is true only for PHP lists', function (mixed $value, bool $expected): void {
        expect(Json::isList($value))->toBe($expected);
    })->with([
        'empty array' => [[], true],
        'list' => [[1, 2], true],
        'sparse keys' => [[1 => 'a'], false],
        'assoc' => [['a' => 1], false],
        'object' => [new stdClass, false],
        'string' => ['[]', false],
        'null' => [null, false],
    ]);
});

describe('JsonPointer', function (): void {
    test('escapes segments', function (string|int $segment, string $expected): void {
        expect(JsonPointer::escape($segment))->toBe($expected);
    })->with([
        ['plain', 'plain'],
        ['a/b', 'a~1b'],
        ['~', '~0'],
        ['~/', '~0~1'],
        ['/~', '~1~0'],
        [5, '5'],
        ['', ''],
    ]);

    test('appends and builds pointers from segments', function (): void {
        expect(JsonPointer::append('/a', 'b/c'))->toBe('/a/b~1c')
            ->and(JsonPointer::append('', 0))->toBe('/0')
            ->and(JsonPointer::fromSegments([]))->toBe('')
            ->and(JsonPointer::fromSegments(['a', 0, 'x/y', '~z', '']))->toBe('/a/0/x~1y/~0z/');
    });

    test('splits and unescapes pointers', function (string $pointer, array $segments): void {
        expect(JsonPointer::segments($pointer))->toBe($segments);
    })->with([
        'root' => ['', []],
        'empty key' => ['/', ['']],
        'simple' => ['/a/0', ['a', '0']],
        'escapes' => ['/a~1b/~0', ['a/b', '~']],
        '~01 is ~1' => ['/~01', ['~1']],
        '~10 is /0' => ['/~10', ['/0']],
    ]);

    test('round-trips segments', function (): void {
        $segments = ['a/b', '~1', '/0', '', 'plain'];

        expect(JsonPointer::segments(JsonPointer::fromSegments($segments)))->toBe($segments);
    });

    test('resolves pointers in a JSON tree', function (string $pointer, array $expected): void {
        $document = fs_tree([
            'a' => ['b' => [10, 20, ['c' => null]]],
            'x/y' => 1,
            '~' => 2,
            '' => 3,
        ]);

        expect(JsonPointer::get($document, $pointer))->toBe($expected);
    })->with([
        'array index' => ['/a/b/1', [true, 20]],
        'existing null' => ['/a/b/2/c', [true, null]],
        'index out of range' => ['/a/b/3', [false, null]],
        'leading zero index' => ['/a/b/01', [false, null]],
        'dash index' => ['/a/b/-', [false, null]],
        'non-numeric index' => ['/a/b/x', [false, null]],
        'missing property' => ['/a/missing', [false, null]],
        'escaped slash' => ['/x~1y', [true, 1]],
        'escaped tilde' => ['/~0', [true, 2]],
        'empty key' => ['/', [true, 3]],
        'below a scalar' => ['/a/b/1/deeper', [false, null]],
    ]);

    test('returns the whole document for the empty pointer', function (): void {
        $document = fs_tree(['a' => 1]);

        expect(JsonPointer::get($document, ''))->toBe([true, $document]);
    });

    test('resolves into nested lists and ignores assoc array keys', function (): void {
        expect(JsonPointer::get([[1, 2], [3]], '/1/0'))->toBe([true, 3])
            ->and(JsonPointer::get(['k' => 1], '/k'))->toBe([false, null])
            ->and(JsonPointer::get('scalar', '/0'))->toBe([false, null]);
    });
});
