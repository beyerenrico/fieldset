import { describe, expect, it } from 'vitest';
import {
    canonicalJson,
    checksum,
    compareCodePoints,
    decodeJson,
    JsonDecodeError,
} from '../src/index.ts';
import { toPointer } from '../src/json/pointer.ts';
import { getOwn, isJsonInteger, toJson } from '../src/json/types.ts';

describe('decodeJson', () => {
    it('parses JSON text', () => {
        expect(decodeJson('{"a":[1,"x",null]}')).toEqual({ a: [1, 'x', null] });
    });

    it('throws JsonDecodeError on malformed input', () => {
        expect(() => decodeJson('{"a":')).toThrow(JsonDecodeError);
        expect(() => decodeJson('nope')).toThrow(/Invalid JSON/);
    });
});

describe('canonicalJson', () => {
    it('sorts keys by code point, not UTF-16 code unit', () => {
        // U+FF61 sorts before U+1F600 by code point, but after it by UTF-16 code unit.
        const value = { '\u{1F600}': 1, '｡': 2, a: 3 };
        expect(canonicalJson(value)).toBe('{"a":3,"｡":2,"\u{1F600}":1}');
    });

    it('keeps numbers in shortest round-trip form and normalizes -0', () => {
        expect(canonicalJson([1e21, 1e-7, -0, 0.1])).toBe('[1e+21,1e-7,0,0.1]');
    });

    it('skips undefined object members and rejects non-JSON values', () => {
        expect(canonicalJson({ a: undefined, b: true })).toBe('{"b":true}');
        expect(() => canonicalJson(Number.NaN)).toThrow(TypeError);
        expect(() => canonicalJson(Number.POSITIVE_INFINITY)).toThrow(TypeError);
        expect(() => canonicalJson(() => 1)).toThrow(/not valid JSON/);
        expect(() => canonicalJson([undefined])).toThrow(TypeError);
    });

    it('escapes control characters but not slash or non-ASCII', () => {
        expect(canonicalJson('a/ä\t\u0001')).toBe('"a/ä\\t\\u0001"');
    });
});

describe('checksum', () => {
    it('is the SHA-256 of the canonical UTF-8 bytes', () => {
        expect(checksum({ b: 1, a: [true, null, 'x'] })).toBe(
            '54a65415ad370228851a1da4b31b6fd42dc58b19a50d35cae759325f7388ce64',
        );
        expect(checksum({ a: [true, null, 'x'], b: 1 })).toBe(
            checksum({ b: 1, a: [true, null, 'x'] }),
        );
    });
});

describe('compareCodePoints', () => {
    it('orders by code point and length', () => {
        expect(compareCodePoints('a', 'b')).toBe(-1);
        expect(compareCodePoints('b', 'a')).toBe(1);
        expect(compareCodePoints('ab', 'a')).toBe(1);
        expect(compareCodePoints('a', 'a')).toBe(0);
        expect(compareCodePoints('\u{1F600}', '｡')).toBe(1);
    });
});

describe('json helpers', () => {
    it('toJson copies, freezes and drops non-JSON values', () => {
        const source = { a: [1, Number.NaN, -0], b: undefined, c: new Date(0), d: { e: 'x' } };
        const copy = toJson(source);
        expect(copy).toEqual({ a: [1, null, 0], c: null, d: { e: 'x' } });
        expect(Object.isFrozen(copy)).toBe(true);
        expect(toJson(Object.create(null) as object)).toEqual({});
    });

    it('toJson keeps __proto__ as an own member instead of changing the prototype', () => {
        const copy = toJson(JSON.parse('{"__proto__": {"x": 1}, "a": 2}')) as object;
        expect(Object.getPrototypeOf(copy)).toBe(Object.prototype);
        expect(Object.keys(copy)).toEqual(['__proto__', 'a']);
    });

    it('getOwn ignores prototype properties', () => {
        expect(getOwn({}, 'constructor')).toBeUndefined();
        expect(getOwn({ a: 1 }, 'a')).toBe(1);
    });

    it('isJsonInteger limits magnitude to 2^53', () => {
        expect(isJsonInteger(2 ** 53)).toBe(true);
        expect(isJsonInteger(2 ** 54)).toBe(false);
        expect(isJsonInteger(1.5)).toBe(false);
        expect(isJsonInteger('1')).toBe(false);
    });

    it('toPointer escapes RFC 6901 tokens', () => {
        expect(toPointer(['a/b', 'c~d', 0])).toBe('/a~1b/c~0d/0');
        expect(toPointer([])).toBe('');
    });
});
