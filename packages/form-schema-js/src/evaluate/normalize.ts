/** Input normalization (spec §5.5 step 1). */
import type { ElementNode, ScopeNode } from '../definition/model.ts';
import { Decimal, formatDecimal, fractionDigits, isDecimalString } from '../decimal/decimal.ts';
import { toPointer } from '../json/pointer.ts';
import {
    getOwn,
    hasOwn,
    isPlainObject,
    toJson,
    type JsonObject,
    type JsonValue,
} from '../json/types.ts';
import { moneyScale } from './checks/type-checks.ts';
import type { DataPath } from './data.ts';
import type { ValidationError } from './types.ts';

export interface Normalized {
    readonly data: JsonObject;
    readonly errors: readonly ValidationError[];
}

/** Money that passes the type check is rewritten with exactly `scale` fraction digits. */
export function normalizeMoney(value: JsonValue, scale: number): JsonValue {
    return isDecimalString(value) && fractionDigits(value) <= scale
        ? formatDecimal(new Decimal(value), scale)
        : value;
}

interface Rows {
    readonly rows: readonly JsonObject[];
    readonly errors: readonly ValidationError[];
}

interface NormalizedValue {
    readonly value: JsonValue;
    readonly errors: readonly ValidationError[];
}

function normalizeRows(scope: ScopeNode, value: unknown, path: DataPath): Rows {
    if (!Array.isArray(value)) {
        return { rows: [], errors: [{ pointer: toPointer(path), code: 'type_mismatch' }] };
    }
    const rows: JsonObject[] = [];
    const errors: ValidationError[] = [];
    (value as readonly unknown[]).forEach((row, index) => {
        if (!isPlainObject(row)) {
            errors.push({ pointer: toPointer([...path, index]), code: 'type_mismatch' });
            return;
        }
        const normalized = normalizeScope(scope, row, [...path, rows.length]);
        rows.push(normalized.data);
        errors.push(...normalized.errors);
    });
    return { rows, errors };
}

/**
 * A `null` repeater value is an empty value (§5.6), kept as `null` without `type_mismatch`;
 * other non-array values become `[]` with `type_mismatch`.
 */
function normalizeValue(node: ElementNode, value: unknown, path: DataPath): NormalizedValue {
    if (node.rowScope !== null && value !== null) {
        const { rows, errors } = normalizeRows(node.rowScope, value, path);
        return { value: rows, errors };
    }
    const json = toJson(value);
    return {
        value: node.type === 'money' ? normalizeMoney(json, moneyScale(node)) : json,
        errors: [],
    };
}

/**
 * Keeps only keys defined in the scope (recursively in rows); client values of calculated
 * elements are discarded; non-array repeaters become `[]` and non-object rows are dropped, both
 * reported as `type_mismatch`.
 */
export function normalizeScope(scope: ScopeNode, input: unknown, base: DataPath): Normalized {
    const source = isPlainObject(input) ? input : {};
    const entries = [...scope.dataKeys].flatMap(([key, node]) => {
        const value = getOwn(source, key);
        if (!hasOwn(source, key) || value === undefined || node.type === 'calculated') {
            return [];
        }
        return [{ key, ...normalizeValue(node, value, [...base, key]) }];
    });
    return {
        data: Object.fromEntries(entries.map((entry) => [entry.key, entry.value])),
        errors: entries.flatMap((entry) => entry.errors),
    };
}

/** Normalizes a default result like client input; `undefined` means "not applicable". */
export function normalizeDefault(node: ElementNode, value: unknown): JsonValue | undefined {
    if (value === null || value === undefined) {
        return undefined;
    }
    if (node.rowScope !== null) {
        return Array.isArray(value) ? normalizeRows(node.rowScope, value, []).rows : undefined;
    }
    return normalizeValue(node, value, []).value;
}
