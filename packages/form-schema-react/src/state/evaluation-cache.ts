/** Shares the evaluation done in an edit handler with the following render (same data object). */
import {
    evaluate,
    type DefinitionModel,
    type EvaluationContext,
    type EvaluationResult,
    type JsonObject,
} from '@form-schema-app/form-schema-js';

interface Entry {
    readonly model: DefinitionModel;
    readonly context: EvaluationContext;
    readonly result: EvaluationResult;
}

const cache = new WeakMap<JsonObject, Entry>();

export function remember(data: JsonObject, entry: Entry): void {
    cache.set(data, entry);
}

/** evaluate() with a cache hit when the data object was produced by an edit handler. */
export function evaluateCached(
    model: DefinitionModel,
    data: JsonObject,
    context: EvaluationContext,
): EvaluationResult {
    const hit = cache.get(data);
    if (hit !== undefined && hit.model === model && hit.context === context) {
        return hit.result;
    }
    const result = evaluate(model, data, context);
    cache.set(data, { model, context, result });
    return result;
}
