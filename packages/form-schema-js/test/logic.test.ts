import { describe, expect, it } from 'vitest';
import {
    evaluateLogic,
    evaluateLogicStrict,
    jsonLogicDialect,
    KNOWN_OPERATORS,
    MAX_LOGIC_DEPTH,
    LogicError,
    truthy,
} from '../src/index.ts';
import { catString, looseEquals, strictEquals, toNumber } from '../src/logic/coerce.ts';
import { literalVarPath, pathLiteral, visitLogic } from '../src/logic/walk.ts';

const run = (logic: unknown, data: unknown = {}) => evaluateLogic(logic, data);

describe('evaluator basics', () => {
    it('evaluates unknown operators to null; the strict variant throws LogicError', () => {
        expect(run({ '!!': [{ regex: ['a', 'b'] }] })).toBeNull();
        expect(() => evaluateLogicStrict({ regex: ['a', 'b'] }, {})).toThrow(LogicError);
        try {
            evaluateLogicStrict({ nope: [] }, {});
        } catch (error) {
            expect(error).toMatchObject({ code: 'unknown_operator', operator: 'nope' });
        }
    });

    it('treats multi-key objects and primitives as literals and evaluates arrays', () => {
        expect(run({ a: 1, b: 2 })).toEqual({ a: 1, b: 2 });
        expect(run([{ var: 'x' }, 2], { x: 1 })).toEqual([1, 2]);
        expect(run(undefined)).toBeNull();
        expect(jsonLogicDialect.evaluate({ '!!': [1] }, {})).toBe(true);
    });

    it('lists the dialect operators', () => {
        expect(KNOWN_OPERATORS.has('dec.add')).toBe(true);
        expect(KNOWN_OPERATORS.has('min')).toBe(true);
        expect(KNOWN_OPERATORS.has('log')).toBe(false);
    });
});

describe('robustness', () => {
    it('limits nesting to 512 levels of objects and arrays', () => {
        const nest = (levels: number, wrap: (inner: unknown) => unknown): unknown =>
            Array.from({ length: levels }).reduce<unknown>((inner) => wrap(inner), true);
        expect(run(nest(512, (inner) => ({ '!': inner })))).toBe(true);
        expect(run(nest(513, (inner) => ({ '!': inner })))).toBeNull();
        expect(run(nest(256, (inner) => ({ '!': [inner] })))).toBe(true);
        expect(run(nest(257, (inner) => ({ '!': [inner] })))).toBeNull();
        expect(run(nest(20000, (inner) => ({ '!': [inner] })))).toBeNull();
        expect(() =>
            evaluateLogicStrict(
                nest(600, (inner) => [inner]),
                {},
            ),
        ).toThrow(/nested too deeply/);
        expect(MAX_LOGIC_DEPTH).toBe(512);
    });

    it('aggregates very large lists', () => {
        const rows = Array.from({ length: 200000 }, () => ({ v: '0.5' }));
        expect(run({ sum: [{ var: 'rows' }, 'v'] }, { rows })).toBe('100000.0');
        expect(run({ 'dec.add': Array.from({ length: 200000 }, () => '1.25') })).toBe('250000.00');
    });

    it('treats a single non-array min/max operand as an empty list', () => {
        expect(run({ min: { var: 'x' } }, { x: '5' })).toBeNull();
        expect(run({ max: [['2', '7']] })).toBe('7');
    });
});

describe('var and missing', () => {
    it('resolves computed and numeric paths and ignores prototype keys', () => {
        expect(run({ var: { cat: ['a', '.b'] } }, { a: { b: 3 } })).toBe(3);
        expect(run({ var: 1 }, ['x', 'y'])).toBe('y');
        expect(run({ var: 'constructor' }, {})).toBeNull();
        expect(run({ var: 'a.length' }, { a: [1] })).toBeNull();
        expect(run({ var: 'a.b' }, { a: 'str' })).toBeNull();
        expect(run({ var: '' }, null)).toBeNull();
        expect(run({ var: [] }, { a: 1 })).toEqual({ a: 1 });
    });

    it('evaluates missing with argument lists and missing_some edge cases', () => {
        expect(run({ missing: 'a' }, {})).toEqual(['a']);
        expect(run({ missing: [['a', 'b']] }, { a: 0 })).toEqual(['b']);
        expect(run({ missing_some: [1, 'notalist'] }, {})).toEqual([]);
    });
});

describe('control flow', () => {
    it('handles empty operands', () => {
        expect(run({ if: [] })).toBeNull();
        expect(run({ if: ['x'] })).toBe('x');
        expect(run({ or: [] })).toBeNull();
        expect(run({ and: [] })).toBeNull();
        expect(run({ '!': [] })).toBe(true);
    });
});

describe('coercion', () => {
    it('applies truthiness', () => {
        expect(truthy(Number.NaN)).toBe(false);
        expect(truthy(undefined)).toBe(false);
        expect(truthy({})).toBe(true);
        expect(truthy('0')).toBe(true);
    });

    it('implements restricted abstract equality', () => {
        expect(looseEquals([1, 2], '1,2')).toBe(true);
        expect(looseEquals([1, 2], [1, 2])).toBe(true);
        expect(looseEquals([], 0)).toBe(true);
        expect(looseEquals({}, '[object Object]')).toBe(false);
        expect(looseEquals(null, undefined)).toBe(false);
        expect(looseEquals('a', true)).toBe(false);
        expect(looseEquals(false, '0')).toBe(true);
        expect(looseEquals(' 1 ', 1)).toBe(true);
    });

    it('implements strict equality', () => {
        expect(strictEquals([], [])).toBe(false);
        expect(strictEquals({}, {})).toBe(false);
        expect(strictEquals(null, null)).toBe(true);
        expect(strictEquals(undefined, undefined)).toBe(false);
    });

    it('converts to numbers and strings', () => {
        expect(toNumber(null)).toBe(0);
        expect(toNumber(undefined)).toBeNaN();
        expect(toNumber([1])).toBeNaN();
        expect(toNumber('')).toBe(0);
        expect(catString([1, null, [true, 'x']])).toBe('1,,true,x');
        expect(catString({ a: 1 })).toBe('[object Object]');
        expect(catString(-0)).toBe('0');
        expect(catString(false)).toBe('false');
    });

    it('compares strings by code point and mixed values numerically', () => {
        expect(run({ '<': ['｡', '\u{1F600}'] })).toBe(true);
        expect(run({ '<=': ['b', 'a'] })).toBe(false);
        expect(run({ '>=': [true, 1] })).toBe(true);
        expect(run({ '>': [[2], 1] })).toBe(false);
        expect(run({ '>': [5, 1, 10] })).toBe(true);
        expect(run({ '<=': [1, 5, 3] })).toBe(false);
    });
});

describe('strings and arrays', () => {
    it('in with non-string needles and non-container haystacks', () => {
        expect(run({ in: [5, 'a5'] })).toBe(true);
        expect(run({ in: [null, 'is null'] })).toBe(true);
        expect(run({ in: ['a', null] })).toBe(false);
        expect(run({ in: [[1], [[1]]] })).toBe(false);
    });

    it('substr counts code points and clamps', () => {
        expect(run({ substr: ['a\u{1F600}b', 1, 1] })).toBe('\u{1F600}');
        expect(run({ substr: ['abc', -10] })).toBe('abc');
        expect(run({ substr: ['abc', 10] })).toBe('');
        expect(run({ substr: ['abc', 'x'] })).toBe('abc');
        expect(run({ substr: ['abc', 1, -5] })).toBe('');
        expect(run({ substr: ['abc', 0, null] })).toBe('');
        expect(run({ substr: [null, 0] })).toBe('');
    });

    it('iteration operators on non-arrays', () => {
        expect(run({ map: [null, { var: '' }] })).toEqual([]);
        expect(run({ filter: ['abc', true] })).toEqual([]);
        expect(run({ reduce: [null, 1, 'init'] })).toBe('init');
        expect(run({ reduce: [[1, 2], { var: 'current' }] })).toBe(2);
        expect(run({ all: [null, true] })).toBe(false);
        expect(run({ none: [null, true] })).toBe(true);
        expect(run({ some: [[0, 0], { var: '' }] })).toBe(false);
    });

    it('merge and cat', () => {
        expect(run({ merge: [] })).toEqual([]);
        expect(run({ merge: [[[1]], 2] })).toEqual([[1], 2]);
        expect(run({ cat: [1.5, [1, 2], {}] })).toBe('1.51,2[object Object]');
    });
});

describe('IEEE arithmetic', () => {
    it('returns null for non-finite results', () => {
        expect(run({ '+': ['a', 1] })).toBeNull();
        expect(run({ '/': [1, 0] })).toBeNull();
        expect(run({ '%': [1, 0] })).toBeNull();
        expect(run({ '*': [] })).toBeNull();
        expect(run({ '-': [] })).toBeNull();
        expect(run({ '-': [0] })).toBe(0);
        expect(Object.is(run({ '-': [0] }), 0)).toBe(true);
    });

    it('converts operands like comparisons', () => {
        expect(run({ '+': [] })).toBe(0);
        expect(run({ '+': ['1', true, null] })).toBe(2);
        expect(run({ '*': ['2', 3] })).toBe(6);
        expect(run({ '+': [0.1, 0.2] })).toBe(0.30000000000000004);
    });
});

describe('decimal operators', () => {
    it('validates scale operands', () => {
        expect(run({ 'dec.div': ['1', '3', 21] })).toBeNull();
        expect(run({ 'dec.div': ['1', '3', -1] })).toBeNull();
        expect(run({ 'dec.div': ['1', '3', '2'] })).toBeNull();
        expect(run({ 'dec.div': ['1', '3', 20] })).toBe('0.33333333333333333333');
        expect(run({ 'dec.round': ['1.5', 1.5] })).toBeNull();
        expect(run({ 'dec.round': [null, 1] })).toBeNull();
        expect(run({ 'dec.div': [null, '1', 2] })).toBeNull();
    });

    it('handles empty and single operand lists', () => {
        expect(run({ 'dec.add': [] })).toBeNull();
        expect(run({ 'dec.sub': ['5'] })).toBe('5');
        expect(run({ 'dec.sub': ['5', '1', '1.5'] })).toBe('2.5');
        expect(run({ 'dec.mul': ['1.5', '2', '-1'] })).toBe('-3.0');
        expect(run({ 'dec.lt': ['1', 'x'] })).toBe(false);
    });

    it('aggregates with and without row keys', () => {
        expect(run({ sum: [{ var: 'rows' }, 'v'] }, { rows: [{ v: '1' }, 'x', null] })).toBe('1');
        expect(run({ sum: [['1', '2'], 3] })).toBe('3');
        expect(run({ max: [{ var: 'rows' }, 'v'] }, { rows: [{ v: '1.50' }, { v: '1.5' }] })).toBe(
            '1.50',
        );
        // A literal one-key object is an operation in JsonLogic, so `v` is an unknown operator.
        expect(run({ sum: [[{ v: '1' }], 'v'] })).toBeNull();
        expect(run({ max: [null, '3', 'x'] })).toBe('3');
        expect(run({ min: ['2', 1] })).toBe('1');
        expect(run({ min: [] })).toBeNull();
        expect(run({ count: [[0, '', null, false]] })).toBe(3);
    });
});

describe('static traversal', () => {
    it('marks per-item arguments of iteration operators', () => {
        const visits: string[] = [];
        visitLogic(
            [
                { map: [{ var: 'r' }, { var: 'x' }] },
                { reduce: [{ var: 'r' }, { var: 'y' }, { var: 'z' }] },
            ],
            ({ op, args, inIteration }) => {
                if (op === 'var') {
                    visits.push(`${String(args[0])}:${String(inIteration)}`);
                }
            },
        );
        expect(visits).toEqual(['r:false', 'x:true', 'r:false', 'y:true', 'z:false']);
    });

    it('extracts literal var paths', () => {
        expect(literalVarPath({ var: 'a.b' })).toBe('a.b');
        expect(literalVarPath({ var: ['a', 1] })).toBe('a');
        expect(literalVarPath({ var: 2 })).toBe('2');
        expect(literalVarPath({ var: { cat: [] } })).toBeNull();
        expect(literalVarPath({ cat: [] })).toBeNull();
        expect(pathLiteral(undefined)).toBe('');
        expect(pathLiteral(true)).toBeNull();
    });
});
