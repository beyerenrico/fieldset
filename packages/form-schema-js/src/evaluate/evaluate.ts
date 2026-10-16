/** Evaluation algorithm (spec §5.5): normalize, settle, strip, validate. */
import { dependencyGraph, evaluationOrder } from '../definition/dependencies.ts';
import { parseDefinition, type DefinitionModel, type ScopeNode } from '../definition/model.ts';
import { canonicalJson } from '../json/codec.ts';
import { toPointer } from '../json/pointer.ts';
import { getOwn, hasOwn, isPlainObject, type JsonObject, type JsonValue } from '../json/types.ts';
import { truthy } from '../logic/coerce.ts';
import { validateValue } from './checks/validate-value.ts';
import { getAt, type DataPath } from './data.ts';
import { listInstances, type Instance } from './instances.ts';
import { normalizeScope } from './normalize.ts';
import {
    applyDefaults,
    computeCalculated,
    computeVisibility,
    evaluateFor,
    resetCalculated,
    type Runtime,
} from './passes.ts';
import type { EvaluationContext, EvaluationResult, ValidationError } from './types.ts';
import { builtins, type EvalState } from './view.ts';

const MAX_ROUNDS = 5;

function sameVisibility(a: EvalState['visibility'], b: EvalState['visibility']): boolean {
    const keys = Object.keys(a);
    return keys.length === Object.keys(b).length && keys.every((key) => a[key] === b[key]);
}

/**
 * Up to 5 rounds of visibility → defaults → calculated values until a round changes nothing.
 * A round is stable when visibility and the data (calculated values and applied defaults) are
 * unchanged; defaults count because a default applied after the visibility pass can only affect
 * visibility in the next round.
 */
function settle(runtime: Runtime, initial: EvalState): EvalState {
    let state = initial;
    for (let round = 0; round < MAX_ROUNDS; round++) {
        const before = state;
        state = computeCalculated(
            runtime,
            applyDefaults(runtime, computeVisibility(runtime, state)),
        );
        const stable =
            sameVisibility(before.visibility, state.visibility) &&
            canonicalJson(before.data) === canonicalJson(state.data);
        if (stable) {
            break;
        }
    }
    return state;
}

/** Removes invisible values; visible calculated elements are always present. */
function stripScope(
    scope: ScopeNode,
    object: JsonObject,
    base: DataPath,
    visibility: EvalState['visibility'],
): JsonObject {
    const entries = [...scope.dataKeys].flatMap(([key, node]): [string, JsonValue][] => {
        const path = [...base, key];
        const kept = node.type === 'calculated' || hasOwn(object, key);
        if (visibility[toPointer(path)] !== true || !kept) {
            return [];
        }
        const value = (getOwn(object, key) as JsonValue | undefined) ?? null;
        const rowScope = node.rowScope;
        if (rowScope === null || !Array.isArray(value)) {
            return [[key, value]];
        }
        const rows = (value as readonly JsonValue[]).map((row, index) =>
            stripScope(rowScope, isPlainObject(row) ? row : {}, [...path, index], visibility),
        );
        return [[key, rows]];
    });
    return Object.fromEntries(entries);
}

function isRequired(runtime: Runtime, instance: Instance, state: EvalState): boolean {
    const raw = instance.node.raw;
    if (getOwn(raw, 'required') === true) {
        return true;
    }
    return (
        hasOwn(raw, 'requiredIf') &&
        truthy(evaluateFor(runtime, instance, state, getOwn(raw, 'requiredIf')))
    );
}

/** Validates every visible data element in document order, rows in index order. */
function validateAll(runtime: Runtime, state: EvalState, data: JsonObject): ValidationError[] {
    return listInstances(runtime.model, data).flatMap((instance) => {
        const { node } = instance;
        if (
            !node.isData ||
            node.type === 'calculated' ||
            state.visibility[instance.pointer] !== true
        ) {
            return [];
        }
        const issue = validateValue(
            node,
            getAt(data, instance.path),
            isRequired(runtime, instance, state),
        );
        return issue === null ? [] : [{ pointer: instance.pointer, ...issue }];
    });
}

function initialVisibility(model: DefinitionModel, data: JsonObject): Record<string, boolean> {
    return Object.fromEntries(
        listInstances(model, data).map((instance) => [instance.pointer, true]),
    );
}

/**
 * Evaluates submission `data` against a definition (raw JSON or parsed model) with the host
 * `context`. Inputs are never mutated.
 */
export function evaluate(
    definition: unknown,
    data: unknown,
    context: EvaluationContext = {},
): EvaluationResult {
    const model = parseDefinition(definition);
    const runtime: Runtime = {
        model,
        context: builtins(context),
        calculationOrder: evaluationOrder(dependencyGraph(model)),
    };
    const normalized = normalizeScope(model.root, data, []);
    const start = resetCalculated(runtime, normalized.data);
    const state = settle(runtime, { data: start, visibility: initialVisibility(model, start) });
    const stripped = stripScope(model.root, state.data, [], state.visibility);
    return {
        data: stripped,
        errors: [...normalized.errors, ...validateAll(runtime, state, stripped)],
        visibility: state.visibility,
    };
}
