/** JsonLogic dialect evaluator (spec §4, §5.3; ADR 0021). */
import { collectionOperators } from './ops/collections.ts';
import { coreOperators } from './ops/core.ts';
import { dateOperators } from './ops/date.ts';
import { decimalOperators } from './ops/decimal.ts';
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

/** Nesting limit; deeper expressions throw `LogicError('max_depth')` instead of a stack overflow. */
export const MAX_LOGIC_DEPTH = 512;

let depth = 0;

/**
 * Evaluates a JsonLogic expression against `data` (the scope object). Arrays evaluate to the
 * list of their evaluated items; objects with more than one key and primitives are literals.
 * Throws `LogicError` for operators outside the dialect and for nesting beyond `MAX_LOGIC_DEPTH`.
 */
export function evaluateLogic(logic: unknown, data: unknown): unknown {
    if (depth >= MAX_LOGIC_DEPTH) {
        throw new LogicError('max_depth', '');
    }
    depth++;
    try {
        return evaluateNode(logic, data);
    } finally {
        depth--;
    }
}

function evaluateNode(logic: unknown, data: unknown): unknown {
    if (Array.isArray(logic)) {
        return (logic as readonly unknown[]).map((item) => evaluateLogic(item, data));
    }
    if (!isOperation(logic)) {
        return logic ?? null;
    }
    const { op, args } = operationOf(logic);
    const operator = OPERATORS.get(op);
    if (operator === undefined) {
        throw new LogicError('unknown_operator', op);
    }
    return operator(args, data, evaluateLogic) ?? null;
}

/** Pluggable evaluator interface (ADR 0021). */
export interface LogicEvaluator {
    evaluate(logic: unknown, data: unknown): unknown;
}

export const jsonLogicDialect: LogicEvaluator = { evaluate: evaluateLogic };
