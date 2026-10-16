/** JSON decoding, canonical JSON (spec §6) and checksums. */
import { sha256 } from '@noble/hashes/sha2.js';
import { bytesToHex, utf8ToBytes } from '@noble/hashes/utils.js';
import { isPlainObject, type JsonValue } from './types.ts';

export class JsonDecodeError extends Error {
    override readonly name = 'JsonDecodeError';
}

/** Parses JSON text. Throws `JsonDecodeError` with the parser message on malformed input. */
export function decodeJson(text: string): JsonValue {
    try {
        return JSON.parse(text) as JsonValue;
    } catch (error) {
        const reason = error instanceof Error ? error.message : String(error);
        throw new JsonDecodeError(`Invalid JSON: ${reason}`);
    }
}

/** Compares two strings by Unicode code point (not UTF-16 code unit). */
export function compareCodePoints(a: string, b: string): number {
    const left = Array.from(a);
    const right = Array.from(b);
    const length = Math.min(left.length, right.length);
    for (let index = 0; index < length; index++) {
        const diff = (left[index]?.codePointAt(0) ?? 0) - (right[index]?.codePointAt(0) ?? 0);
        if (diff !== 0) {
            return diff < 0 ? -1 : 1;
        }
    }
    return Math.sign(left.length - right.length);
}

/**
 * Canonical JSON (spec §6): object keys sorted by code point, no insignificant whitespace,
 * `/` and non-ASCII characters unescaped, numbers in shortest round-trip form.
 * Throws `TypeError` for values that are not representable as JSON.
 */
export function canonicalJson(value: unknown): string {
    if (value === null || typeof value === 'boolean' || typeof value === 'string') {
        return JSON.stringify(value);
    }
    if (typeof value === 'number') {
        if (!Number.isFinite(value)) {
            throw new TypeError('Non-finite numbers are not valid JSON');
        }
        return JSON.stringify(value);
    }
    if (Array.isArray(value)) {
        return `[${value.map((item: unknown) => canonicalJson(item)).join(',')}]`;
    }
    if (isPlainObject(value)) {
        const members = Object.keys(value)
            .filter((key) => value[key] !== undefined)
            .sort(compareCodePoints)
            .map((key) => `${JSON.stringify(key)}:${canonicalJson(value[key])}`);
        return `{${members.join(',')}}`;
    }
    throw new TypeError(`Value of type ${typeof value} is not valid JSON`);
}

/** Lowercase hex SHA-256 of the UTF-8 canonical JSON of `value` (spec §6). */
export function checksum(value: unknown): string {
    return bytesToHex(sha256(utf8ToBytes(canonicalJson(value))));
}
