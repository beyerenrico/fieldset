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
 * non-finite numbers and non-JSON values (functions, symbols, bigint, class instances) become `null`.
 */
export function toJson(value: unknown): JsonValue {
    if (value === null || typeof value === 'string' || typeof value === 'boolean') {
        return value;
    }
    if (typeof value === 'number') {
        return Number.isFinite(value) ? normalizeZero(value) : null;
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
