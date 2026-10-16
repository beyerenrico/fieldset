/** JSON value types and small type guards shared by every module. */

export type JsonPrimitive = string | number | boolean | null;
export type JsonValue = JsonPrimitive | readonly JsonValue[] | JsonObject;
export interface JsonObject {
    readonly [key: string]: JsonValue;
}

/** A plain JSON-like object (not null, not an array). */
export type RawObject = Readonly<Record<string, unknown>>;

export function isPlainObject(value: unknown): value is RawObject {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function isUnknownArray(value: unknown): value is readonly unknown[] {
    return Array.isArray(value);
}

export function hasOwn(object: RawObject, key: string): boolean {
    return Object.prototype.hasOwnProperty.call(object, key);
}

/** Own property value, or `undefined` when the property is absent (prototype keys are ignored). */
export function getOwn(object: RawObject, key: string): unknown {
    return hasOwn(object, key) ? object[key] : undefined;
}

export function stringProp(object: RawObject, key: string): string | undefined {
    const value = getOwn(object, key);
    return typeof value === 'string' ? value : undefined;
}

export function numberProp(object: RawObject, key: string): number | undefined {
    const value = getOwn(object, key);
    return typeof value === 'number' && Number.isFinite(value) ? value : undefined;
}

export function integerProp(object: RawObject, key: string): number | undefined {
    const value = numberProp(object, key);
    return value !== undefined && Number.isInteger(value) ? value : undefined;
}

export function objectProp(object: RawObject, key: string): RawObject | undefined {
    const value = getOwn(object, key);
    return isPlainObject(value) ? value : undefined;
}

export function arrayProp(object: RawObject, key: string): readonly unknown[] | undefined {
    const value = getOwn(object, key);
    return isUnknownArray(value) ? value : undefined;
}

/** JSON integer as used by the spec: integral value with magnitude up to 2^53. */
export function isJsonInteger(value: unknown): value is number {
    return typeof value === 'number' && Number.isInteger(value) && Math.abs(value) <= 2 ** 53;
}

/**
 * Deep copy of a JSON-like value into fresh frozen JSON. `undefined` object members are dropped,
 * non-JSON values (functions, symbols, bigint, class instances) become `null`. Non-finite numbers
 * (e.g. `1e400` parsed as `Infinity`) are kept so validation can reject them.
 */
export function toJson(value: unknown): JsonValue {
    if (value === null || typeof value === 'string' || typeof value === 'boolean') {
        return value;
    }
    if (typeof value === 'number') {
        return normalizeZero(value);
    }
    if (Array.isArray(value)) {
        return Object.freeze(value.map((item: unknown) => toJson(item)));
    }
    if (isPlainObject(value) && isPlainPrototype(value)) {
        return Object.freeze(copyMembers(value));
    }
    return null;
}

/** Own members as fresh own properties (`Object.fromEntries` never invokes `__proto__` setters). */
function copyMembers(value: RawObject): Record<string, JsonValue> {
    return Object.fromEntries(
        Object.entries(value)
            .filter(([, member]) => member !== undefined)
            .map(([key, member]) => [key, toJson(member)]),
    );
}

function isPlainPrototype(value: object): boolean {
    const proto: unknown = Object.getPrototypeOf(value);
    return proto === null || proto === Object.prototype;
}

/** `-0` is not representable in JSON output; normalize it to `0`. */
export function normalizeZero(value: number): number {
    return Object.is(value, -0) ? 0 : value;
}

/** True when `value` nests objects/arrays deeper than `limit` levels (a scalar has depth 0). */
export function exceedsDepth(value: unknown, limit: number): boolean {
    if (typeof value !== 'object' || value === null) {
        return false;
    }
    if (limit <= 0) {
        return true;
    }
    const children: readonly unknown[] = Array.isArray(value) ? value : Object.values(value);
    return children.some((child) => exceedsDepth(child, limit - 1));
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
    if (!isPlainObject(left) || !isPlainObject(right)) {
        return false;
    }
    const keys = Object.keys(left);
    return (
        keys.length === Object.keys(right).length &&
        keys.every((key) => hasOwn(right, key) && jsonEquals(left[key], right[key]))
    );
}
