/** String, array, iteration and IEEE-754 arithmetic operators (spec §4.2). */
import { normalizeZero } from '../../json/types.ts';
import { catString, strictEquals, toNumber, truthy } from '../coerce.ts';
import { evaluateArgs, type Evaluate, type Operator } from '../operation.ts';

function asList(value: unknown): readonly unknown[] {
    return Array.isArray(value) ? (value as readonly unknown[]) : [];
}

const inOperator: Operator = (args, data, evaluate) => {
    const [needle, haystack] = evaluateArgs(args, data, evaluate);
    if (typeof haystack === 'string') {
        return haystack.includes(needle === null ? 'null' : catString(needle));
    }
    return Array.isArray(haystack) && haystack.some((item: unknown) => strictEquals(item, needle));
};

function integerArg(value: unknown): number {
    const number = Math.trunc(toNumber(value));
    return Number.isFinite(number) ? number : 0;
}

/** `substr` on code points, with negative start/length counted from the end. */
const substr: Operator = (args, data, evaluate) => {
    const [source, startArg, lengthArg] = evaluateArgs(args, data, evaluate);
    const chars = Array.from(catString(source));
    const rawStart = integerArg(startArg);
    const start = Math.min(
        rawStart < 0 ? Math.max(chars.length + rawStart, 0) : rawStart,
        chars.length,
    );
    if (args.length < 3) {
        return chars.slice(start).join('');
    }
    const length = integerArg(lengthArg);
    const end =
        length < 0
            ? Math.max(chars.length + length, start)
            : Math.min(start + length, chars.length);
    return chars.slice(start, end).join('');
};

const merge: Operator = (args, data, evaluate) =>
    evaluateArgs(args, data, evaluate).flatMap((value) =>
        Array.isArray(value) ? (value as unknown[]) : [value],
    );

function perItem(args: readonly unknown[], data: unknown, evaluate: Evaluate): readonly unknown[] {
    return asList(evaluate(args[0], data));
}

const map: Operator = (args, data, evaluate) =>
    perItem(args, data, evaluate).map((item) => evaluate(args[1], item));

const filter: Operator = (args, data, evaluate) =>
    perItem(args, data, evaluate).filter((item) => truthy(evaluate(args[1], item)));

const reduce: Operator = (args, data, evaluate) => {
    const initial = args.length > 2 ? evaluate(args[2], data) : null;
    return perItem(args, data, evaluate).reduce<unknown>(
        (accumulator, current) => evaluate(args[1], { current, accumulator }),
        initial,
    );
};

const all: Operator = (args, data, evaluate) => {
    const items = perItem(args, data, evaluate);
    return items.length > 0 && items.every((item) => truthy(evaluate(args[1], item)));
};

const some: Operator = (args, data, evaluate) =>
    perItem(args, data, evaluate).some((item) => truthy(evaluate(args[1], item)));

const none: Operator = (args, data, evaluate) => !(some(args, data, evaluate) as boolean);

/** IEEE-754 result; NaN and infinities are not JSON and become `null`. */
function finite(value: number): number | null {
    return Number.isFinite(value) ? normalizeZero(value) : null;
}

function numbers(args: readonly unknown[], data: unknown, evaluate: Evaluate): number[] {
    return evaluateArgs(args, data, evaluate).map(toNumber);
}

const plus: Operator = (args, data, evaluate) =>
    finite(numbers(args, data, evaluate).reduce((sum, value) => sum + value, 0));

const times: Operator = (args, data, evaluate) => {
    const values = numbers(args, data, evaluate);
    return values.length === 0
        ? null
        : finite(values.reduce((product, value) => product * value, 1));
};

const minus: Operator = (args, data, evaluate) => {
    const [a = Number.NaN, b] = numbers(args, data, evaluate);
    if (args.length === 0) {
        return null;
    }
    return finite(b === undefined ? -a : a - b);
};

function binaryNumber(apply: (a: number, b: number) => number): Operator {
    return (args, data, evaluate) => {
        const [a = Number.NaN, b = Number.NaN] = numbers(args, data, evaluate);
        return finite(apply(a, b));
    };
}

export const collectionOperators: Readonly<Record<string, Operator>> = {
    in: inOperator,
    cat: (args, data, evaluate) => evaluateArgs(args, data, evaluate).map(catString).join(''),
    substr,
    merge,
    map,
    filter,
    reduce,
    all,
    none,
    some,
    '+': plus,
    '-': minus,
    '*': times,
    '/': binaryNumber((a, b) => a / b),
    '%': binaryNumber((a, b) => a % b),
};
