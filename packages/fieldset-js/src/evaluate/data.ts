/** Immutable reads and writes inside submission data. */
import type { PathSegment } from '../json/pointer.ts';
import { getOwn, isPlainObject, type JsonObject, type JsonValue } from '../json/types.ts';

export type DataPath = readonly PathSegment[];

/** Value at `path`, or `undefined` when any segment is missing. */
export function getAt(root: JsonValue, path: DataPath): JsonValue | undefined {
    let current: JsonValue | undefined = root;
    for (const segment of path) {
        if (typeof segment === 'number') {
            current = Array.isArray(current)
                ? (current as readonly JsonValue[])[segment]
                : undefined;
        } else {
            current = isPlainObject(current)
                ? (getOwn(current, segment) as JsonValue | undefined)
                : undefined;
        }
        if (current === undefined) {
            return undefined;
        }
    }
    return current;
}

/** Returns a copy of `root` with `value` written at `path` (copies only along the path). */
export function setAt(root: JsonValue, path: DataPath, value: JsonValue): JsonValue {
    const [head, ...rest] = path;
    if (head === undefined) {
        return value;
    }
    if (typeof head === 'number') {
        const list: readonly JsonValue[] = Array.isArray(root)
            ? (root as readonly JsonValue[])
            : [];
        const copy = [...list];
        copy[head] = setAt(list[head] ?? null, rest, value);
        return copy;
    }
    const object: JsonObject = isPlainObject(root) ? root : {};
    return {
        ...object,
        [head]: setAt((getOwn(object, head) as JsonValue | undefined) ?? null, rest, value),
    };
}

export function setIn(root: JsonObject, path: DataPath, value: JsonValue): JsonObject {
    return setAt(root, path, value) as JsonObject;
}
