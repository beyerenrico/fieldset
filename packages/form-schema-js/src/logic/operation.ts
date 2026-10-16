/** Shared shapes for JsonLogic operations and the operator table. */
import { getOwn, isPlainObject, type RawObject } from '../json/types.ts';

/** Evaluates a logic expression against a data object. */
export type Evaluate = (logic: unknown, data: unknown) => unknown;

/** An operator receives its raw (unevaluated) arguments so it can evaluate lazily. */
export type Operator = (args: readonly unknown[], data: unknown, evaluate: Evaluate) => unknown;

export type LogicErrorCode = 'unknown_operator' | 'max_depth';

export class LogicError extends Error {
    override readonly name = 'LogicError';
    readonly code: LogicErrorCode;
    readonly operator: string;

    constructor(code: LogicErrorCode, operator: string) {
        super(
            code === 'unknown_operator'
                ? `Unknown JsonLogic operator "${operator}"`
                : 'JsonLogic expression is nested too deeply',
        );
        this.code = code;
        this.operator = operator;
    }
}

/** A JsonLogic operation is a plain object with exactly one key. */
export function isOperation(logic: unknown): logic is RawObject {
    return isPlainObject(logic) && Object.keys(logic).length === 1;
}

export interface Operation {
    readonly op: string;
    readonly args: readonly unknown[];
}

/** Operator name and argument list (a non-array argument becomes a one-element list). */
export function operationOf(logic: RawObject): Operation {
    const op = Object.keys(logic)[0] ?? '';
    const raw = getOwn(logic, op);
    return { op, args: Array.isArray(raw) ? (raw as readonly unknown[]) : [raw] };
}

/** Evaluates every argument eagerly. */
export function evaluateArgs(
    args: readonly unknown[],
    data: unknown,
    evaluate: Evaluate,
): unknown[] {
    return args.map((arg) => evaluate(arg, data));
}

/** Operators whose second argument is evaluated per item (`var` resolves against the item). */
export const ITERATION_OPERATORS: ReadonlySet<string> = new Set([
    'map',
    'filter',
    'reduce',
    'all',
    'none',
    'some',
]);

/** Decimal operators of §5.3 whose literal operands must not be floats. */
export const DECIMAL_OPERATORS: ReadonlySet<string> = new Set([
    'dec.add',
    'dec.sub',
    'dec.mul',
    'dec.div',
    'dec.round',
    'dec.eq',
    'dec.gt',
    'dec.gte',
    'dec.lt',
    'dec.lte',
    'sum',
    'min',
    'max',
]);

/** Built-in operators that must not receive number/money values (lint `decimal_builtin_operator`). */
export const BUILTIN_NUMERIC_OPERATORS: ReadonlySet<string> = new Set([
    '+',
    '-',
    '*',
    '/',
    '%',
    '<',
    '<=',
    '>',
    '>=',
    '==',
    '!=',
    '===',
    '!==',
]);

/** Aggregates that accept a row key as second operand (lint checks it). */
export const ROW_KEY_OPERATORS: ReadonlySet<string> = new Set(['sum', 'min', 'max']);
