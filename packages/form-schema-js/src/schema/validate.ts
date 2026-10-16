/** Meta-schema validation (JSON Schema 2020-12) of a Form Definition. */
import Ajv2020, { type ErrorObject, type ValidateFunction } from 'ajv/dist/2020.js';
import { objectProp, type RawObject } from '../json/types.ts';
import { metaSchema } from '../spec-assets.ts';

export interface SchemaError {
    /** JSON Pointer into the definition (`''` for the root). */
    readonly path: string;
    readonly message: string;
}

export interface SchemaValidationResult {
    readonly valid: boolean;
    readonly errors: readonly SchemaError[];
}

let compiled: ValidateFunction | undefined;

/** `Object.prototype` member names (`constructor`, `toString`, `__proto__`, …). */
const PROTOTYPE_NAMES: readonly string[] = Object.getOwnPropertyNames(Object.prototype);

/**
 * Ajv records evaluated properties for `unevaluatedProperties` in a plain object, so prototype
 * member names look "evaluated" there. The element schema (the only one using
 * `unevaluatedProperties`) additionally forbids those names; no element property uses them.
 */
function hardened(schema: RawObject): RawObject {
    const defs = objectProp(schema, '$defs') ?? {};
    const element = objectProp(defs, 'element') ?? {};
    const propertyNames = { not: { enum: PROTOTYPE_NAMES } };
    return { ...schema, $defs: { ...defs, element: { ...element, propertyNames } } };
}

function validator(): ValidateFunction {
    if (compiled === undefined) {
        // ownProperties: `properties`/`required` must only see own members.
        const ajv = new Ajv2020({ allErrors: true, strict: false, ownProperties: true });
        compiled = ajv.compile(hardened(metaSchema));
    }
    return compiled;
}

function describe(error: ErrorObject): SchemaError {
    const params = error.params as Readonly<Record<string, unknown>>;
    if (error.keyword === 'propertyNames') {
        const name = String(params['propertyName']);
        return {
            path: error.instancePath,
            message: `must NOT have unevaluated properties (${name})`,
        };
    }
    const property = params['unevaluatedProperty'] ?? params['additionalProperty'];
    const detail = typeof property === 'string' ? ` (${property})` : '';
    return { path: error.instancePath, message: `${error.message ?? error.keyword}${detail}` };
}

/** The `not` error behind a `propertyNames` error duplicates it. */
function isReported(error: ErrorObject): boolean {
    return !error.schemaPath.endsWith('/propertyNames/not');
}

/** Validates a definition against `meta-schema.json` (structure only; semantics are linted). */
export function validateDefinition(definition: unknown): SchemaValidationResult {
    const validate = validator();
    const valid = validate(definition);
    const errors = valid ? [] : (validate.errors ?? []).filter(isReported).map(describe);
    return { valid, errors };
}
