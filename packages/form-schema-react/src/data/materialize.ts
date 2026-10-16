/** Copies values applied by `evaluate()` (defaults) into the raw data where the raw value is empty. */
import type {
    DefinitionModel,
    JsonObject,
    JsonValue,
    ScopeNode,
} from '@form-schema-app/form-schema-js';
import { isJsonObject } from './path.ts';

function materializeRows(
    scope: ScopeNode,
    rows: readonly JsonValue[],
    evaluated: readonly JsonValue[],
): readonly JsonValue[] {
    const next = rows.map((row, index) => {
        const evaluatedRow = evaluated[index];
        return isJsonObject(row) && isJsonObject(evaluatedRow)
            ? materializeScope(scope, row, evaluatedRow)
            : row;
    });
    return next.some((row, index) => row !== rows[index]) ? next : rows;
}

function materializeScope(scope: ScopeNode, raw: JsonObject, evaluated: JsonObject): JsonObject {
    let result: Record<string, JsonValue> | null = null;
    for (const [key, node] of scope.dataKeys) {
        if (node.type === 'calculated' || !Object.hasOwn(evaluated, key)) {
            continue;
        }
        const rawValue = Object.hasOwn(raw, key) ? raw[key] : undefined;
        const evaluatedValue = evaluated[key] ?? null;
        let next: JsonValue | undefined = rawValue;
        if (node.rowScope !== null && Array.isArray(rawValue) && Array.isArray(evaluatedValue)) {
            if (rawValue.length === evaluatedValue.length) {
                next = materializeRows(
                    node.rowScope,
                    rawValue as readonly JsonValue[],
                    evaluatedValue as readonly JsonValue[],
                );
            }
        } else if ((rawValue === undefined || rawValue === null) && evaluatedValue !== null) {
            next = evaluatedValue;
        }
        if (next !== rawValue && next !== undefined) {
            result ??= { ...raw };
            result[key] = next;
        }
    }
    return result ?? raw;
}

/**
 * Returns `raw` with every non-null evaluated value (applied defaults) filled in where the raw
 * value is missing or `null`. Calculated values are not copied; repeater rows are merged by index
 * when both lists have the same length. Returns `raw` itself when nothing changes.
 */
export function materializeDefaults(
    model: DefinitionModel,
    raw: JsonObject,
    evaluated: JsonObject,
): JsonObject {
    return materializeScope(model.root, raw, evaluated);
}
