/** The repeated passes of §5.5 step 2: visibility, defaults, calculated values. */
import type { DefinitionModel, ElementNode, PageNode } from '../definition/model.ts';
import { formatDecimal, toDec } from '../decimal/decimal.ts';
import {
    getOwn,
    hasOwn,
    isJsonInteger,
    stringProp,
    type JsonObject,
    type JsonValue,
} from '../json/types.ts';
import { catString, truthy } from '../logic/coerce.ts';
import { getAt, setIn } from './data.ts';
import { instancesOf, listInstances, type Instance, type Located } from './instances.ts';
import { boundedInteger } from './checks/type-checks.ts';
import { normalizeDefault } from './normalize.ts';
import { evaluateSafely, scopeObject, type EvalState } from './view.ts';

export interface Runtime {
    readonly model: DefinitionModel;
    readonly context: JsonObject;
    readonly calculationOrder: readonly ElementNode[];
}

export function evaluateFor(
    runtime: Runtime,
    located: Pick<Located, 'rows'>,
    state: EvalState,
    logic: unknown,
): unknown {
    return evaluateSafely(logic, scopeObject(located, runtime.model.root, state, runtime.context));
}

function withVisibility(state: EvalState, pointer: string, visible: boolean): EvalState {
    return state.visibility[pointer] === visible
        ? state
        : { ...state, visibility: { ...state.visibility, [pointer]: visible } };
}

function ownVisibility(
    runtime: Runtime,
    located: Pick<Located, 'rows'>,
    raw: Readonly<Record<string, unknown>>,
    state: EvalState,
): boolean {
    return (
        !hasOwn(raw, 'visibleIf') ||
        truthy(evaluateFor(runtime, located, state, getOwn(raw, 'visibleIf')))
    );
}

/**
 * Visibility top-down in document order. Each decision is visible to later evaluations of the
 * same pass; elements not decided yet keep their previous state.
 */
export function computeVisibility(runtime: Runtime, state: EvalState): EvalState {
    const instances = listInstances(runtime.model, state.data);
    const pages = new Map<number, boolean>();
    const pageVisible = (page: PageNode, current: EvalState): boolean => {
        const known = pages.get(page.index);
        const visible = known ?? ownVisibility(runtime, { rows: [] }, page.raw, current);
        pages.set(page.index, visible);
        return visible;
    };
    const decided = instances.reduce<EvalState>((current, instance) => {
        const page = runtime.model.pages.find(
            (candidate) => candidate.index === instance.node.page,
        );
        const parentVisible =
            instance.parentPointer === null
                ? page !== undefined && pageVisible(page, current)
                : current.visibility[instance.parentPointer] === true;
        const visible =
            parentVisible && ownVisibility(runtime, instance, instance.node.raw, current);
        return withVisibility(current, instance.pointer, visible);
    }, state);
    const visibility = Object.fromEntries(
        instances.map((instance) => [
            instance.pointer,
            decided.visibility[instance.pointer] === true,
        ]),
    );
    return { data: decided.data, visibility };
}

function wantsDefault(instance: Instance, state: EvalState): boolean {
    const { node } = instance;
    if (!node.isData || node.type === 'calculated' || !hasOwn(node.raw, 'default')) {
        return false;
    }
    if (state.visibility[instance.pointer] !== true) {
        return false;
    }
    const value = getAt(state.data, instance.path);
    return (
        value === undefined || value === null || (node.type === 'hidden' && !isHiddenValue(value))
    );
}

/** `hidden` keeps a client-supplied string, integer or boolean; otherwise its default applies. */
function isHiddenValue(value: JsonValue): boolean {
    return typeof value === 'string' || typeof value === 'boolean' || isJsonInteger(value);
}

/** Applies defaults (§5.2) of visible elements whose value is missing or `null`. */
export function applyDefaults(runtime: Runtime, state: EvalState): EvalState {
    return listInstances(runtime.model, state.data).reduce<EvalState>((current, instance) => {
        if (!wantsDefault(instance, current)) {
            return current;
        }
        const result = evaluateFor(
            runtime,
            instance,
            current,
            getOwn(instance.node.raw, 'default'),
        );
        const value = normalizeDefault(instance.node, result);
        return value === undefined
            ? current
            : { ...current, data: setIn(current.data, instance.path, value) };
    }, state);
}

/** Converts an expression result per `format` and `scale` (§5.4). */
export function formatCalculated(node: ElementNode, result: unknown): JsonValue {
    const format = stringProp(node.raw, 'format') ?? 'number';
    if (format === 'text') {
        if (result === null || result === undefined) {
            return null;
        }
        return typeof result === 'string' ? result : catString(result);
    }
    const decimal = toDec(result);
    const scale = boundedInteger(node, 'scale', 10, format === 'money' ? 2 : 6);
    return decimal === null ? null : formatDecimal(decimal.value, scale);
}

/** Computes calculated values in dependency order, every row instance in index order. */
export function computeCalculated(runtime: Runtime, state: EvalState): EvalState {
    return runtime.calculationOrder.reduce<EvalState>(
        (outer, node) =>
            instancesOf(node, outer.data).reduce<EvalState>((current, located) => {
                const result = evaluateFor(
                    runtime,
                    located,
                    current,
                    getOwn(node.raw, 'expression'),
                );
                return {
                    ...current,
                    data: setIn(current.data, located.path, formatCalculated(node, result)),
                };
            }, outer),
        state,
    );
}

/** Every calculated instance set to `null` (start of step 2). */
export function resetCalculated(runtime: Runtime, data: JsonObject): JsonObject {
    return runtime.calculationOrder.reduce<JsonObject>(
        (outer, node) =>
            instancesOf(node, outer).reduce<JsonObject>(
                (current, located) => setIn(current, located.path, null),
                outer,
            ),
        data,
    );
}
