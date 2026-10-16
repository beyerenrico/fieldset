/** Logic view of the data and per-instance scope objects (spec §4.3, §5.5 step 1). */
import type { ElementNode, ScopeNode } from '../definition/model.ts';
import { getOwn, isPlainObject, type JsonObject, type JsonValue } from '../json/types.ts';
import { evaluateLogic } from '../logic/evaluator.ts';
import { LogicError } from '../logic/operation.ts';
import type { Located } from './instances.ts';
import { getAt } from './data.ts';
import { isEmpty, typeIssue } from './checks/type-checks.ts';
import type { EvaluationContext } from './types.ts';

/** Snapshot of the evaluation: data plus visibility (absent pointer = not computed yet = visible). */
export interface EvalState {
    readonly data: JsonObject;
    readonly visibility: Readonly<Record<string, boolean>>;
}

const rootViews = new WeakMap<EvalState, JsonObject>();

/** Built-in scope members (§4.3); missing context values resolve to `null`. */
export function builtins(context: EvaluationContext): JsonObject {
    return {
        $today: context.today ?? null,
        $now: context.now ?? null,
        $user: { name: context.user?.name ?? null, email: context.user?.email ?? null },
        $org: { name: context.org?.name ?? null },
        $form: { version: context.formVersion ?? null },
    };
}

function viewValue(
    node: ElementNode,
    value: JsonValue | undefined,
    pointer: string,
    state: EvalState,
): JsonValue {
    if (value === undefined || state.visibility[pointer] === false) {
        return null;
    }
    if (node.rowScope !== null) {
        const scope = node.rowScope;
        return Array.isArray(value)
            ? (value as readonly JsonValue[]).map((row, index) =>
                  viewOfScope(scope, row, `${pointer}/${String(index)}`, state),
              )
            : null;
    }
    return isEmpty(value) || typeIssue(node, value) === null ? value : null;
}

/**
 * View of one scope object: every data key defined in the scope (value or `null`); invisible
 * values and values failing their type check are `null`.
 */
export function viewOfScope(
    scope: ScopeNode,
    object: unknown,
    pointer: string,
    state: EvalState,
): JsonObject {
    const source = isPlainObject(object) ? object : {};
    return Object.fromEntries(
        [...scope.dataKeys].map(([key, node]) => [
            key,
            viewValue(
                node,
                getOwn(source, key) as JsonValue | undefined,
                `${pointer}/${key}`,
                state,
            ),
        ]),
    );
}

function rootView(root: ScopeNode, state: EvalState): JsonObject {
    const cached = rootViews.get(state);
    if (cached !== undefined) {
        return cached;
    }
    const view = viewOfScope(root, state.data, '', state);
    rootViews.set(state, view);
    return view;
}

/**
 * Scope object of an instance: built-ins, `$root`, root keys, then the keys of every enclosing
 * row (inner rows shadow outer keys) and `$row` for the innermost row.
 */
export function scopeObject(
    located: Pick<Located, 'rows'>,
    root: ScopeNode,
    state: EvalState,
    context: JsonObject,
): JsonObject {
    const rootValues = rootView(root, state);
    return located.rows.reduce<JsonObject>(
        (scope, row) => {
            const rowView = viewOfScope(row.scope, getAt(state.data, row.path), row.pointer, state);
            return { ...scope, ...rowView, $row: rowView };
        },
        { ...context, $root: rootValues, ...rootValues },
    );
}

/** Evaluates logic; an unknown operator (a lint error) or excessive nesting evaluates to `null`. */
export function evaluateSafely(logic: unknown, scope: JsonObject): unknown {
    try {
        return evaluateLogic(logic, scope);
    } catch (error) {
        if (error instanceof LogicError) {
            return null;
        }
        throw error;
    }
}
