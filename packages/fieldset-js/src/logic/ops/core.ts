/** Data access, control flow, equality and comparison operators (spec §4.1–§4.3). */
import { getOwn, isPlainObject } from '../../json/types.ts';
import {
    catString,
    compare,
    looseEquals,
    strictEquals,
    toNumber,
    truthy,
    type Comparison,
} from '../coerce.ts';
import { evaluateArgs, type Operator } from '../operation.ts';

const ARRAY_INDEX = /^(0|[1-9][0-9]*)$/;

function step(current: unknown, segment: string): unknown {
    if (Array.isArray(current)) {
        return ARRAY_INDEX.test(segment)
            ? (current as readonly unknown[])[Number(segment)]
            : undefined;
    }
    return isPlainObject(current) ? getOwn(current, segment) : undefined;
}

/**
 * Resolves a dotted `var` path. Empty/`null` paths return the whole scope; a `null` or missing
 * result returns `fallback`. Only own properties and array indexes are traversed.
 */
export function resolvePath(data: unknown, path: unknown, fallback: unknown): unknown {
    if (path === undefined || path === null || path === '') {
        return data ?? null;
    }
    let current: unknown = data;
    for (const segment of catString(path).split('.')) {
        current = step(current, segment);
        if (current === undefined || current === null) {
            return fallback;
        }
    }
    return current;
}

function missingKeys(keys: readonly unknown[], data: unknown): unknown[] {
    return keys.filter((key) => {
        const value = resolvePath(data, key, null);
        return value === null || value === '';
    });
}

const varOperator: Operator = (args, data, evaluate) => {
    const [path, fallback] = evaluateArgs(args, data, evaluate);
    return resolvePath(data, path, args.length > 1 ? fallback : null);
};

const missing: Operator = (args, data, evaluate) => {
    const values = evaluateArgs(args, data, evaluate);
    const first = values[0];
    return missingKeys(Array.isArray(first) ? (first as readonly unknown[]) : values, data);
};

const missingSome: Operator = (args, data, evaluate) => {
    const [need, options] = evaluateArgs(args, data, evaluate);
    const keys: readonly unknown[] = Array.isArray(options) ? (options as readonly unknown[]) : [];
    const absent = missingKeys(keys, data);
    return keys.length - absent.length >= toNumber(need) ? [] : absent;
};

const ifOperator: Operator = (args, data, evaluate) => {
    let index = 0;
    for (; index < args.length - 1; index += 2) {
        if (truthy(evaluate(args[index], data))) {
            return evaluate(args[index + 1], data);
        }
    }
    return index === args.length - 1 ? evaluate(args[index], data) : null;
};

const or: Operator = (args, data, evaluate) => {
    let current: unknown = null;
    for (const arg of args) {
        current = evaluate(arg, data);
        if (truthy(current)) {
            return current;
        }
    }
    return current;
};

const and: Operator = (args, data, evaluate) => {
    let current: unknown = null;
    for (const arg of args) {
        current = evaluate(arg, data);
        if (!truthy(current)) {
            return current;
        }
    }
    return current;
};

function binary(test: (left: unknown, right: unknown) => boolean): Operator {
    return (args, data, evaluate) => {
        const [left, right] = evaluateArgs(args, data, evaluate);
        return test(left, right);
    };
}

/** `<` and `<=` accept a third operand ("between"); `>` and `>=` use two. */
function comparison(op: Comparison, between: boolean): Operator {
    return (args, data, evaluate) => {
        const [a, b, c] = evaluateArgs(args, data, evaluate);
        if (between && args.length > 2) {
            return compare(op, a, b) && compare(op, b, c);
        }
        return compare(op, a, b);
    };
}

export const coreOperators: Readonly<Record<string, Operator>> = {
    var: varOperator,
    missing,
    missing_some: missingSome,
    if: ifOperator,
    '?:': ifOperator,
    or,
    and,
    '!': (args, data, evaluate) => !truthy(evaluate(args[0], data)),
    '!!': (args, data, evaluate) => truthy(evaluate(args[0], data)),
    '==': binary(looseEquals),
    '!=': binary((left, right) => !looseEquals(left, right)),
    '===': binary(strictEquals),
    '!==': binary((left, right) => !strictEquals(left, right)),
    '<': comparison('<', true),
    '<=': comparison('<=', true),
    '>': comparison('>', false),
    '>=': comparison('>=', false),
};
