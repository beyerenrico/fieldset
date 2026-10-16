/** Immutable access to submission data by path (`['items', 0, 'qty']`) and RFC 6901 pointers. */
import type { JsonObject, JsonValue } from '@form-schema-app/form-schema-js';

export type DataPath = readonly (string | number)[];

function isObject(value: unknown): value is JsonObject {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** RFC 6901 pointer of a data path, e.g. `/items/0/qty`. */
export function toPointer(path: DataPath): string {
    return path
        .map((segment) => `/${String(segment).replace(/~/g, '~0').replace(/\//g, '~1')}`)
        .join('');
}

/** Reads a value; `undefined` when any segment is missing. */
export function getAt(data: JsonValue | undefined, path: DataPath): JsonValue | undefined {
    let current: JsonValue | undefined = data;
    for (const segment of path) {
        if (typeof segment === 'number') {
            current = Array.isArray(current)
                ? (current as readonly JsonValue[])[segment]
                : undefined;
        } else {
            current =
                isObject(current) && Object.hasOwn(current, segment) ? current[segment] : undefined;
        }
        if (current === undefined) {
            return undefined;
        }
    }
    return current;
}

function setInArray(
    current: JsonValue | undefined,
    index: number,
    rest: DataPath,
    value: JsonValue | undefined,
): JsonValue {
    const rows = Array.isArray(current) ? [...(current as readonly JsonValue[])] : [];
    while (rows.length < index) {
        rows.push({});
    }
    const child = setIn(rows[index], rest, value, typeof rest[0] === 'number' ? [] : {});
    rows[index] = child ?? null;
    return rows;
}

function setIn(
    current: JsonValue | undefined,
    path: DataPath,
    value: JsonValue | undefined,
    empty: JsonValue,
): JsonValue | undefined {
    const [head, ...rest] = path;
    if (head === undefined) {
        return value;
    }
    if (typeof head === 'number') {
        return setInArray(current ?? empty, head, rest, value);
    }
    const object: JsonObject = isObject(current) ? current : {};
    const next = setIn(
        Object.hasOwn(object, head) ? object[head] : undefined,
        rest,
        value,
        typeof rest[0] === 'number' ? [] : {},
    );
    if (next === undefined) {
        return Object.fromEntries(Object.entries(object).filter(([key]) => key !== head));
    }
    return { ...object, [head]: next };
}

/**
 * Returns a copy of `data` with `value` at `path`; missing objects, arrays and rows are created.
 * `undefined` removes the key. Untouched branches are shared.
 */
export function setAt(data: JsonObject, path: DataPath, value: JsonValue | undefined): JsonObject {
    const next = setIn(data, path, value, {});
    return isObject(next) ? next : {};
}

/** Sanitized DOM id for a pointer: `/` → `-` (keys never contain `-`), anything else → `_`. */
export function pointerId(base: string, pointer: string): string {
    return `${base}-f${pointer.replace(/\//g, '-').replace(/[^A-Za-z0-9_-]/g, '_')}`;
}

export function isJsonObject(value: unknown): value is JsonObject {
    return isObject(value);
}
