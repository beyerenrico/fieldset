/** JsonLogic dialect evaluator (spec §4, §5.3; ADR 0021). */
import { collectionOperators } from './ops/collections.ts';
import { coreOperators } from './ops/core.ts';
import { dateOperators } from './ops/date.ts';
import { decimalOperators } from './ops/decimal.ts';
import { exceedsDepth } from '../json/types.ts';
import { isOperation, LogicError, operationOf, type Operator } from './operation.ts';

const OPERATORS: ReadonlyMap<string, Operator> = new Map(
    Object.entries({
        ...coreOperators,
        ...collectionOperators,
        ...decimalOperators,
        ...dateOperators,
    }),
);

/** Every operator name of §4.2 and §5.3. Anything else is lint `unknown_operator`. */
export const KNOWN_OPERATORS: ReadonlySet<string> = new Set(OPERATORS.keys());

/** Logic nested deeper than this (objects and arrays, §4.2) is an evaluation error. */
export const MAX_LOGIC_DEPTH = 512;

const shallowEnough = new WeakMap<object, boolean>();

/** True when the expression nests objects/arrays deeper than 512 levels (cached per object). */
export function isLogicTooDeep(logic: unknown): boolean {
    if (typeof logic !== 'object' || logic === null) {
        return false;
    }
    const cached = shallowEnough.get(logic);
    if (cached !== undefined) {
        return !cached;
    }
    const tooDeep = exceedsDepth(logic, MAX_LOGIC_DEPTH);
    shallowEnough.set(logic, !tooDeep);
    return tooDeep;
}

/**
 * Evaluates a JsonLogic expression against `data` (the scope object). Arrays evaluate to the
 * list of their evaluated items; objects with more than one key and primitives are literals.
 * Throws `LogicError` for operators outside the dialect and for nesting beyond 512 levels.
 */
export function evaluateLogicStrict(logic: unknown, data: unknown): unknown {
    if (isLogicTooDeep(logic)) {
        throw new LogicError('max_depth', '');
    }
    return evaluateNode(logic, data);
}

/**
 * Evaluates a JsonLogic expression; evaluation errors (unknown operators, nesting deeper than
 * 512 levels) make the whole expression `null` (§4.2).
 */
export function evaluateLogic(logic: unknown, data: unknown): unknown {
    try {
        return evaluateLogicStrict(logic, data);
    } catch (error) {
        if (error instanceof LogicError) {
            return null;
        }
        throw error;
    }
}

function evaluateNode(logic: unknown, data: unknown): unknown {
    if (Array.isArray(logic)) {
        return (logic as readonly unknown[]).map((item) => evaluateNode(item, data));
    }
    if (!isOperation(logic)) {
        return logic ?? null;
    }
    const { op, args } = operationOf(logic);
    const operator = OPERATORS.get(op);
    if (operator === undefined) {
        throw new LogicError('unknown_operator', op);
    }
    return operator(args, data, evaluateNode) ?? null;
}

/** Pluggable evaluator interface (ADR 0021). */
export interface LogicEvaluator {
    evaluate(logic: unknown, data: unknown): unknown;
}

export const jsonLogicDialect: LogicEvaluator = { evaluate: evaluateLogic };
