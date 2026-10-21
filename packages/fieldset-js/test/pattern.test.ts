import { describe, expect, it } from 'vitest';
import { compilePattern, isValidPattern, matchesPattern } from '../src/definition/pattern.ts';
import { translatePattern } from '../src/definition/pattern-syntax.ts';

describe('pattern subset (§3.4)', () => {
    it.each([
        '',
        'a|',
        '()',
        '(?:)',
        '[a-]',
        '[-a]',
        '[^^]',
        '[{}()|*.]',
        '[!-\\]]',
        '((a)b)+',
        'a??',
        'a{2,}',
        'a{0,1000}?',
        '(a+)?',
        '(a|b){0,1}',
        '\\u{10FFFF}',
        '\\uD83D',
        '(?!a)b',
        '\\bx\\B',
        '\\t\\n\\r\\f\\v',
        'x/y-z',
    ])('accepts %s', (pattern) => {
        expect(isValidPattern(pattern)).toBe(true);
    });

    it.each([
        '\\u{110000}',
        '\\u{}',
        '\\x4',
        '\\u12',
        '[z-a]',
        '[\\d-z]',
        '[a-\\d]',
        '[\\B]',
        '^*',
        '$+',
        '\\b{2}',
        '(?=a)*',
        'a{1,2',
        'a{x}',
        ')',
        'a)',
        ']',
        '}',
        '{1}',
        'a**',
        'a+?+',
        '(?:a|b)+',
        '((?:a|b))*',
        '(a{2}){0,2}',
        '[a',
        '\\',
        '(?<n>a)\\1',
    ])('rejects %s', (pattern) => {
        expect(isValidPattern(pattern)).toBe(false);
    });

    it('re-emits an escaped hyphen outside classes for Unicode mode', () => {
        expect(translatePattern('a\\-b[\\-x]')).toBe('a-b[\\-x]');
        expect(translatePattern('[-^]')).toBe('[\\-\\^]');
        expect(translatePattern('a{01,02}')).toBe('a{1,2}');
        expect(compilePattern('^\\w+$')?.flags).toBe('u');
    });

    it('treats pathological nesting as invalid instead of throwing', () => {
        expect(translatePattern('('.repeat(100000) + ')'.repeat(100000))).toBeNull();
    });

    it('caches compiled patterns', () => {
        expect(compilePattern('(')).toBeNull();
        expect(compilePattern('(')).toBeNull();
        expect(compilePattern('^a')).toBe(compilePattern('^a'));
    });
});

describe('pattern matching', () => {
    it('is unanchored, code-point based and case-sensitive', () => {
        expect(matchesPattern('b', 'abc')).toBe(true);
        expect(matchesPattern('^.$', '\u{1F600}')).toBe(true);
        expect(matchesPattern('^abc$', 'ABC')).toBe(false);
    });

    it('fails closed above 4,096 code points and ignores invalid patterns', () => {
        expect(matchesPattern('a', 'a'.repeat(4096))).toBe(true);
        expect(matchesPattern('a', 'a'.repeat(4097))).toBe(false);
        expect(matchesPattern('a', '\u{1F600}'.repeat(4096) + 'a')).toBe(false);
        expect(matchesPattern('(a+)+', 'zzz')).toBe(true);
    });
});
