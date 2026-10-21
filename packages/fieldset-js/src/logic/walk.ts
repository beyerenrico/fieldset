/** Static traversal of logic expressions (used by the linter and the dependency graph). */
import { isOperation, ITERATION_OPERATORS, operationOf } from './operation.ts';

export interface OperationVisit {
    readonly op: string;
    readonly args: readonly unknown[];
    /** True inside the per-item argument of `map`, `filter`, `reduce`, `all`, `none`, `some`. */
    readonly inIteration: boolean;
}

/** Visits every operation node in pre-order. */
export function visitLogic(
    logic: unknown,
    visit: (operation: OperationVisit) => void,
    inIteration = false,
): void {
    if (Array.isArray(logic)) {
        for (const item of logic as readonly unknown[]) {
            visitLogic(item, visit, inIteration);
        }
        return;
    }
    if (!isOperation(logic)) {
        return;
    }
    const { op, args } = operationOf(logic);
    visit({ op, args, inIteration });
    args.forEach((arg, index) => {
        visitLogic(arg, visit, inIteration || (ITERATION_OPERATORS.has(op) && index === 1));
    });
}

/** Literal path of a `{"var": …}` operand: a string/number path, `''` for the scope, or `null`. */
export function literalVarPath(operand: unknown): string | null {
    if (!isOperation(operand) || !Object.hasOwn(operand, 'var')) {
        return null;
    }
    const { args } = operationOf(operand);
    return pathLiteral(args[0]);
}

/** A `var` path argument that can be resolved statically, or `null` for computed paths. */
export function pathLiteral(path: unknown): string | null {
    if (path === undefined || path === null) {
        return '';
    }
    if (typeof path === 'string' || typeof path === 'number') {
        return String(path);
    }
    return null;
}
