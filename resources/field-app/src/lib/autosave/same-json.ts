/** Deep JSON equality that ignores object key order (Postgres jsonb reorders keys). */
import type { JsonValue } from '@form-schema-app/form-schema-js';

function isObject(value: unknown): value is Readonly<Record<string, JsonValue>> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function sameJson(a: JsonValue | undefined, b: JsonValue | undefined): boolean {
    if (a === b) {
        return true;
    }
    if (Array.isArray(a) || Array.isArray(b)) {
        return (
            Array.isArray(a) &&
            Array.isArray(b) &&
            a.length === b.length &&
            a.every((item: JsonValue, index) => sameJson(item, (b as readonly JsonValue[])[index]))
        );
    }
    if (!isObject(a) || !isObject(b)) {
        return false;
    }
    const keys = Object.keys(a);
    return (
        keys.length === Object.keys(b).length &&
        keys.every((key) => Object.hasOwn(b, key) && sameJson(a[key], b[key]))
    );
}
