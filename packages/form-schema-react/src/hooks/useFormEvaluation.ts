import {
    evaluate,
    parseDefinition,
    type DefinitionModel,
    type EvaluationContext,
    type EvaluationResult,
    type FormDefinition,
    type JsonObject,
} from '@form-schema-app/form-schema-js';
import { useMemo } from 'react';

export interface FormEvaluation {
    readonly model: DefinitionModel;
    readonly result: EvaluationResult;
}

const EMPTY_CONTEXT: EvaluationContext = {};

/** Parses the definition once and evaluates the data with the spec evaluator on every change. */
export function useFormEvaluation(
    definition: FormDefinition | DefinitionModel,
    data: JsonObject,
    context: EvaluationContext = EMPTY_CONTEXT,
): FormEvaluation {
    const model = useMemo(() => parseDefinition(definition), [definition]);
    const result = useMemo(() => evaluate(model, data, context), [model, data, context]);
    return { model, result };
}
