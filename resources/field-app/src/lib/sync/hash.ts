/**
 * A stable hash of draft data: key order is ignored like in sameJson (Postgres jsonb reorders keys),
 * so the data of a PUT whose answer was lost can be recognised in a later server copy.
 */
import type { JsonObject, JsonValue } from '@form-schema-app/form-schema-js';

function isObject(value: JsonValue): value is JsonObject {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** JSON with sorted object keys at every level. */
export function canonicalJson(value: JsonValue): string {
    if (Array.isArray(value)) {
        return `[${value.map((item: JsonValue) => canonicalJson(item)).join(',')}]`;
    }
    if (isObject(value)) {
        const members = Object.keys(value)
            .toSorted()
            .map((key) => `${JSON.stringify(key)}:${canonicalJson(value[key] ?? null)}`);
        return `{${members.join(',')}}`;
    }
    return JSON.stringify(value);
}

/** cyrb53 (public domain): a fast 53-bit string hash, as 14 hex digits. */
function cyrb53(text: string): string {
    let h1 = 0xdeadbeef;
    let h2 = 0x41c6ce57;
    for (let index = 0; index < text.length; index++) {
        const char = text.charCodeAt(index);
        h1 = Math.imul(h1 ^ char, 2654435761);
        h2 = Math.imul(h2 ^ char, 1597334677);
    }
    h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
    h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);
    const hash = 4294967296 * (2097151 & h2) + (h1 >>> 0);
    return hash.toString(16).padStart(14, '0');
}

export function jsonHash(value: JsonValue): string {
    return cyrb53(canonicalJson(value));
}
