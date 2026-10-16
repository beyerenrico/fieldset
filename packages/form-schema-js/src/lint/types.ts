/** Lint finding shapes (spec §7). */
import type { JsonValue } from '../json/types.ts';

export type LintSeverity = 'error' | 'warning';

export type LintCode =
    | 'duplicate_key'
    | 'duplicate_page_key'
    | 'duplicate_id'
    | 'duplicate_option_value'
    | 'unknown_reference'
    | 'unknown_operator'
    | 'decimal_builtin_operator'
    | 'float_literal'
    | 'cycle'
    | 'invalid_numbering_format'
    | 'invalid_repeater_bounds'
    | 'invalid_pattern'
    | 'reserved_type'
    | 'key_locked'
    | 'key_type_changed'
    | 'unknown_role'
    | 'duplicate_role'
    | 'empty_page';

export interface LintFinding {
    readonly severity: LintSeverity;
    readonly code: LintCode;
    /** JSON Pointer into the definition. */
    readonly path: string;
    readonly params?: Readonly<Record<string, JsonValue>>;
}

const WARNINGS: ReadonlySet<LintCode> = new Set(['unknown_role', 'empty_page']);

export function finding(
    code: LintCode,
    path: string,
    params?: Readonly<Record<string, JsonValue>>,
): LintFinding {
    const severity: LintSeverity = WARNINGS.has(code) ? 'warning' : 'error';
    return params === undefined ? { severity, code, path } : { severity, code, path, params };
}
