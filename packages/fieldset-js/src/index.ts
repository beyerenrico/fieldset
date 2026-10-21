/** Reference TypeScript SDK of Fieldset Spec 1.0. */
export {
    canonicalJson,
    checksum,
    compareCodePoints,
    decodeJson,
    JsonDecodeError,
} from './json/codec.ts';
export type { JsonObject, JsonPrimitive, JsonValue } from './json/types.ts';
export { validateDefinition } from './schema/validate.ts';
export type { SchemaError, SchemaValidationResult } from './schema/validate.ts';
export {
    evaluateLogic,
    evaluateLogicStrict,
    jsonLogicDialect,
    KNOWN_OPERATORS,
    MAX_LOGIC_DEPTH,
} from './logic/evaluator.ts';
export type { LogicEvaluator } from './logic/evaluator.ts';
export { LogicError } from './logic/operation.ts';
export { truthy } from './logic/coerce.ts';
export { DefinitionError, isDefinitionModel, parseDefinition } from './definition/model.ts';
export type { DefinitionModel, ElementNode, PageNode, ScopeNode } from './definition/model.ts';
export { evaluate } from './evaluate/evaluate.ts';
export type {
    ErrorCode,
    ErrorParams,
    EvaluationContext,
    EvaluationResult,
    ValidationError,
} from './evaluate/types.ts';
export type * from './definition/types.ts';
export { hasLintErrors, lint } from './lint/lint.ts';
export type { LintCode, LintFinding, LintSeverity } from './lint/types.ts';
export { exportDataSchema } from './export/data-schema.ts';
