/** Structured values: attachment ids, signatures and locations (spec §3.2). */
import { parseDateTime } from '../../date/calendar.ts';
import { getOwn, isPlainObject, type JsonValue, type RawObject } from '../../json/types.ts';

const UUID = /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/;

export function isAttachmentId(value: unknown): boolean {
    return typeof value === 'string' && UUID.test(value);
}

function isDateTime(value: unknown): boolean {
    return typeof value === 'string' && parseDateTime(value) !== null;
}

function isNumberIn(value: unknown, min: number, max: number): boolean {
    return typeof value === 'number' && Number.isFinite(value) && value >= min && value <= max;
}

function hasExactKeys(object: RawObject, keys: readonly string[]): boolean {
    const own = Object.keys(object);
    return own.length === keys.length && keys.every((key) => own.includes(key));
}

/** `{ attachment: uuid, signerName: string, signedAt: date-time }` with no other members. */
export function isSignature(value: JsonValue): boolean {
    if (!isPlainObject(value) || !hasExactKeys(value, ['attachment', 'signerName', 'signedAt'])) {
        return false;
    }
    return (
        isAttachmentId(getOwn(value, 'attachment')) &&
        typeof getOwn(value, 'signerName') === 'string' &&
        isDateTime(getOwn(value, 'signedAt'))
    );
}

/** `{ lat: −90…90, lng: −180…180, accuracy: ≥ 0, capturedAt: date-time }` with no other members. */
export function isLocation(value: JsonValue): boolean {
    if (!isPlainObject(value) || !hasExactKeys(value, ['lat', 'lng', 'accuracy', 'capturedAt'])) {
        return false;
    }
    return (
        isNumberIn(getOwn(value, 'lat'), -90, 90) &&
        isNumberIn(getOwn(value, 'lng'), -180, 180) &&
        isNumberIn(getOwn(value, 'accuracy'), 0, Number.MAX_VALUE) &&
        isDateTime(getOwn(value, 'capturedAt'))
    );
}
