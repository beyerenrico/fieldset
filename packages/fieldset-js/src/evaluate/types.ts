/** Public types of the evaluator (spec §5.5–§5.6). */
import type { JsonObject, JsonValue } from '../json/types.ts';

/** Host-supplied built-ins (§4.3), in the organization's time zone. */
export interface EvaluationContext {
    /** `$today`, `YYYY-MM-DD`. */
    readonly today?: string;
    /** `$now`, RFC 3339. */
    readonly now?: string;
    readonly user?: { readonly name?: string; readonly email?: string };
    readonly org?: { readonly name?: string };
    /** `$form.version`. */
    readonly formVersion?: number | string;
}

export type ErrorCode =
    | 'required'
    | 'type_mismatch'
    | 'decimal_string_required'
    | 'invalid_number'
    | 'scale_exceeded'
    | 'invalid_email'
    | 'invalid_phone'
    | 'invalid_date'
    | 'invalid_time'
    | 'invalid_datetime'
    | 'invalid_option'
    | 'invalid_attachment'
    | 'invalid_signature'
    | 'invalid_location'
    | 'min_length'
    | 'max_length'
    | 'pattern'
    | 'min'
    | 'max'
    | 'step'
    | 'min_items'
    | 'max_items'
    | 'out_of_range';

export type ErrorParams = Readonly<Record<string, JsonValue>>;

/** A failed check of one element value; `pointer` is a JSON Pointer into the submission data. */
export interface ValidationError {
    readonly pointer: string;
    readonly code: ErrorCode;
    readonly params?: ErrorParams;
}

export interface EvaluationResult {
    /** Normalized data with invisible values stripped. */
    readonly data: JsonObject;
    readonly errors: readonly ValidationError[];
    /** Scope pointer of every element (layout included) → visibility. */
    readonly visibility: Readonly<Record<string, boolean>>;
    /**
     * Presentation state (not compared by conformance runners): pointer of every visible data element
     * → required (`required` or a truthy `requiredIf`).
     */
    readonly required: Readonly<Record<string, boolean>>;
    /** Pointer of every visible data element → read-only (truthy `readOnlyIf`, §3 presentation hint). */
    readonly readOnly: Readonly<Record<string, boolean>>;
}
