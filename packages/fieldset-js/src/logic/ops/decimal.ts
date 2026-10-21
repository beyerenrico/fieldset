/** Decimal and aggregate operators (spec §5.3). */
import {
    Decimal,
    divideHalfUp,
    formatDec,
    formatDecimal,
    toDec,
    type Dec,
} from '../../decimal/decimal.ts';
import { getOwn, isJsonInteger, isPlainObject } from '../../json/types.ts';
import { evaluateArgs, type Evaluate, type Operator } from '../operation.ts';

const MAX_SCALE = 20;

/** Largest scale of the operands (`reduce` keeps huge lists off the call stack). */
function largestScale(values: readonly Dec[]): number {
    return values.reduce((scale, item) => Math.max(scale, item.scale), 0);
}

function decimals(args: readonly unknown[], data: unknown, evaluate: Evaluate): (Dec | null)[] {
    return evaluateArgs(args, data, evaluate).map(toDec);
}

/** All operands when every one is a decimal, otherwise `null` (§5.3: `null` if any is `null`). */
function allPresent(values: readonly (Dec | null)[]): [Dec, ...Dec[]] | null {
    const present = values.filter((value): value is Dec => value !== null);
    const [first, ...rest] = present;
    return first !== undefined && present.length === values.length ? [first, ...rest] : null;
}

/** Scale operand of `dec.div` / `dec.round`: a JSON integer from 0 to 20. */
function scaleOf(value: unknown): number | null {
    return isJsonInteger(value) && value >= 0 && value <= MAX_SCALE ? value : null;
}

function additive(sign: 1 | -1): Operator {
    return (args, data, evaluate) => {
        const values = allPresent(decimals(args, data, evaluate));
        if (values === null) {
            return null;
        }
        const [first, ...rest] = values;
        const total = rest.reduce<Decimal>(
            (sum, item) => (sign === 1 ? sum.plus(item.value) : sum.minus(item.value)),
            first.value,
        );
        return formatDecimal(total, largestScale(values));
    };
}

const multiply: Operator = (args, data, evaluate) => {
    const values = allPresent(decimals(args, data, evaluate));
    if (values === null) {
        return null;
    }
    const [first, ...rest] = values;
    const product = rest.reduce<Decimal>((acc, item) => acc.times(item.value), first.value);
    return formatDecimal(
        product,
        values.reduce((sum, item) => sum + item.scale, 0),
    );
};

const divide: Operator = (args, data, evaluate) => {
    const [a, b, rawScale] = evaluateArgs(args, data, evaluate);
    const [dividend, divisor, scale] = [toDec(a), toDec(b), scaleOf(rawScale)];
    if (dividend === null || divisor === null || scale === null) {
        return null;
    }
    const quotient = divideHalfUp(dividend.value, divisor.value, scale);
    return quotient === null ? null : formatDecimal(quotient, scale);
};

const round: Operator = (args, data, evaluate) => {
    const [a, rawScale] = evaluateArgs(args, data, evaluate);
    const [value, scale] = [toDec(a), scaleOf(rawScale)];
    return value === null || scale === null ? null : formatDecimal(value.value, scale);
};

function comparison(test: (order: number) => boolean): Operator {
    return (args, data, evaluate) => {
        const [a, b] = decimals(args, data, evaluate);
        return a != null && b != null && test(a.value.comparedTo(b.value));
    };
}

/** Item value for aggregates: the item itself, or its `rowKey` member when a string key is given. */
function pick(item: unknown, rowKey: unknown): unknown {
    if (typeof rowKey !== 'string') {
        return item;
    }
    return isPlainObject(item) ? getOwn(item, rowKey) : undefined;
}

function listItems(list: unknown, rowKey: unknown): unknown[] {
    return Array.isArray(list) ? (list as unknown[]).map((item) => pick(item, rowKey)) : [];
}

function present(values: readonly unknown[]): Dec[] {
    return values.map(toDec).filter((value): value is Dec => value !== null);
}

const sum: Operator = (args, data, evaluate) => {
    const [list, rowKey] = evaluateArgs(args, data, evaluate);
    const values = present(listItems(list, rowKey));
    if (values.length === 0) {
        return '0';
    }
    const total = values.reduce<Decimal>((acc, item) => acc.plus(item.value), new Decimal(0));
    return formatDecimal(total, largestScale(values));
};

/**
 * `min`/`max`: aggregate form `[array, rowKey?]` when the first operand is an array or is the only
 * operand (a non-array then counts as an empty list), otherwise the variadic form `[a, b, …]`.
 */
function extreme(wins: (candidate: Decimal, best: Decimal) => boolean): Operator {
    return (args, data, evaluate) => {
        const values = evaluateArgs(args, data, evaluate);
        const first = values[0];
        const aggregate = Array.isArray(first) || values.length === 1;
        const candidates = present(aggregate ? listItems(first, values[1]) : values);
        const best = candidates.reduce<Dec | null>(
            (current, item) =>
                current === null || wins(item.value, current.value) ? item : current,
            null,
        );
        return best === null ? null : formatDec(best);
    };
}

const count: Operator = (args, data, evaluate) => {
    const list = evaluate(args[0], data);
    return Array.isArray(list) ? (list as unknown[]).filter((item) => item != null).length : 0;
};

export const decimalOperators: Readonly<Record<string, Operator>> = {
    'dec.add': additive(1),
    'dec.sub': additive(-1),
    'dec.mul': multiply,
    'dec.div': divide,
    'dec.round': round,
    'dec.eq': comparison((order) => order === 0),
    'dec.gt': comparison((order) => order > 0),
    'dec.gte': comparison((order) => order >= 0),
    'dec.lt': comparison((order) => order < 0),
    'dec.lte': comparison((order) => order <= 0),
    sum,
    min: extreme((candidate, best) => candidate.lt(best)),
    max: extreme((candidate, best) => candidate.gt(best)),
    count,
};
