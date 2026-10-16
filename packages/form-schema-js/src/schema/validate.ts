/** Meta-schema validation (JSON Schema 2020-12) of a Form Definition. */
import Ajv2020, { type ErrorObject, type ValidateFunction } from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';
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

function validator(): ValidateFunction {
    if (compiled === undefined) {
        const ajv = new Ajv2020({ allErrors: true, strict: false });
        addFormats(ajv, { keywords: false });
        compiled = ajv.compile(metaSchema);
    }
    return compiled;
}

function describe(error: ErrorObject): SchemaError {
    const params = error.params as Readonly<Record<string, unknown>>;
    const property = params['unevaluatedProperty'] ?? params['additionalProperty'];
    const detail = typeof property === 'string' ? ` (${property})` : '';
    return { path: error.instancePath, message: `${error.message ?? error.keyword}${detail}` };
}

/** Validates a definition against `meta-schema.json` (structure only; semantics are linted). */
export function validateDefinition(definition: unknown): SchemaValidationResult {
    const validate = validator();
    const valid = validate(definition);
    return { valid, errors: valid ? [] : (validate.errors ?? []).map(describe) };
}
