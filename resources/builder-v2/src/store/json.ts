/** Small immutable JSON helpers (path copying for structural sharing). */
import type { JsonObject, JsonValue } from '@fieldset/fieldset-js';

export type PathSegment = string | number;
export type Path = readonly PathSegment[];

export function isObject(value: unknown): value is JsonObject {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function isList(value: unknown): value is readonly JsonValue[] {
    return Array.isArray(value);
}

export function str(value: unknown): string | null {
    return typeof value === 'string' ? value : null;
}

/** Own list property, or an empty list when absent or not an array. */
export function listProp(node: JsonObject, key: string): readonly JsonValue[] {
    const value = Object.prototype.hasOwnProperty.call(node, key) ? node[key] : undefined;
    return isList(value) ? value : [];
}

export function getIn(root: JsonValue, path: Path): JsonValue | undefined {
    let current: JsonValue | undefined = root;
    for (const segment of path) {
        if (typeof segment === 'number') {
            current = isList(current) ? current[segment] : undefined;
        } else {
            current = isObject(current) ? current[segment] : undefined;
        }
        if (current === undefined) {
            return undefined;
        }
    }
    return current;
}

/** Returns a copy of `root` with `value` at `path`; untouched branches keep their identity. */
export function setIn(root: JsonValue, path: Path, value: JsonValue): JsonValue {
    if (path.length === 0) {
        return value;
    }
    const [head, ...rest] = path;
    if (typeof head === 'number') {
        const list = isList(root) ? root : [];
        const child = list[head] ?? null;
        const copy = [...list];
        copy[head] = setIn(child, rest, value);
        return copy;
    }
    const object = isObject(root) ? root : {};
    const child = object[head as string] ?? null;
    return { ...object, [head as string]: setIn(child, rest, value) };
}

/** Object without the given keys (key order of the rest is kept). */
export function omit(object: JsonObject, keys: readonly string[]): JsonObject {
    return Object.fromEntries(Object.entries(object).filter(([key]) => !keys.includes(key)));
}

/** Structural equality of JSON values. */
export function jsonEquals(left: unknown, right: unknown): boolean {
    if (left === right) {
        return true;
    }
    if (Array.isArray(left) || Array.isArray(right)) {
        return (
            Array.isArray(left) &&
            Array.isArray(right) &&
            left.length === right.length &&
            left.every((item: unknown, index) => jsonEquals(item, right[index]))
        );
    }
    if (!isObject(left) || !isObject(right)) {
        return false;
    }
    const keys = Object.keys(left);
    return (
        keys.length === Object.keys(right).length &&
        keys.every(
            (key) =>
                Object.prototype.hasOwnProperty.call(right, key) &&
                jsonEquals(left[key], right[key]),
        )
    );
}
