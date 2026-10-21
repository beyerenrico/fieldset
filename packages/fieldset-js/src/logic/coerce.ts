/** Truthiness, equality and comparison of the JsonLogic dialect (spec §4.1). */
import { compareCodePoints } from '../json/codec.ts';
import { isPlainObject } from '../json/types.ts';

/** Falsy: `false`, `null`, `0`, `""`, `[]` (and missing values). Everything else is truthy. */
export function truthy(value: unknown): boolean {
    if (Array.isArray(value)) {
        return value.length > 0;
    }
    if (value === null || value === undefined || value === false || value === '') {
        return false;
    }
    return !(typeof value === 'number' && (value === 0 || Number.isNaN(value)));
}

/** Number formatting used by `cat`: shortest round-trip form. */
function numberString(value: number): string {
    return Object.is(value, -0) ? '0' : String(value);
}

/**
 * String form used by `cat`: `null` → `""`, numbers in shortest round-trip form, booleans
 * `true`/`false`; arrays join their items' string forms with `,`; objects are `[object Object]`.
 */
export function catString(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }
    if (typeof value === 'string') {
        return value;
    }
    if (typeof value === 'number') {
        return numberString(value);
    }
    if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    }
    if (Array.isArray(value)) {
        return value.map((item: unknown) => catString(item)).join(',');
    }
    return '[object Object]';
}

/**
 * Number conversion for `<`, `<=`, `>`, `>=` and arithmetic: `null` → 0, booleans → 1/0,
 * strings via JavaScript `Number(string)` (trimmed, empty string = 0, unparsable = NaN),
 * anything else (arrays, objects, missing) → NaN.
 */
export function toNumber(value: unknown): number {
    switch (typeof value) {
        case 'number':
            return value;
        case 'boolean':
            return value ? 1 : 0;
        case 'string':
            return Number(value);
        default:
            return value === null ? 0 : Number.NaN;
    }
}

type Primitive = string | number | boolean | null;

function toPrimitive(value: unknown): Primitive | undefined {
    if (Array.isArray(value)) {
        return catString(value);
    }
    if (isPlainObject(value) || value === undefined) {
        return undefined;
    }
    return value as Primitive;
}

function loosePrimitive(left: Primitive, right: Primitive): boolean {
    if (left === null || right === null) {
        return left === right;
    }
    if (typeof left === 'boolean') {
        return loosePrimitive(left ? 1 : 0, right);
    }
    if (typeof right === 'boolean') {
        return loosePrimitive(left, right ? 1 : 0);
    }
    if (typeof left === typeof right) {
        return left === right;
    }
    return toNumber(left) === toNumber(right);
}

/**
 * `==`: JavaScript abstract equality restricted to JSON values. Objects are never equal; arrays
 * are compared through their comma-joined string form.
 */
export function looseEquals(left: unknown, right: unknown): boolean {
    const a = toPrimitive(left);
    const b = toPrimitive(right);
    if (a === undefined || b === undefined) {
        return false;
    }
    return loosePrimitive(a, b);
}

/** `===`: same type and value; arrays and objects are never strictly equal. */
export function strictEquals(left: unknown, right: unknown): boolean {
    if (typeof left === 'object' && left !== null) {
        return false;
    }
    return left === right && left !== undefined;
}

export type Comparison = '<' | '<=' | '>' | '>=';

/** Relational comparison: code-point order for two strings, otherwise numeric (NaN → false). */
export function compare(op: Comparison, left: unknown, right: unknown): boolean {
    if (typeof left === 'string' && typeof right === 'string') {
        return applyComparison(op, compareCodePoints(left, right), 0);
    }
    const a = toNumber(left);
    const b = toNumber(right);
    if (Number.isNaN(a) || Number.isNaN(b)) {
        return false;
    }
    return applyComparison(op, a, b);
}

function applyComparison(op: Comparison, a: number, b: number): boolean {
    switch (op) {
        case '<':
            return a < b;
        case '<=':
            return a <= b;
        case '>':
            return a > b;
        case '>=':
            return a >= b;
    }
}
