/**
 * One `Idempotency-Key` per (submission, revision) for submit and per (submission, recipient) for
 * "send to customer", kept in sessionStorage so retries and reloads replay the same key; a new
 * revision or recipient gets a new key.
 */
import { newId } from '../ids.ts';
import { readJson, removeKey, writeJson } from '../storage/local.ts';

const keyFor = (uuid: string, revision: number): string => `idem.${uuid}.${String(revision)}`;

export function getIdempotencyKey(uuid: string, revision: number): string {
    const stored = readJson<unknown>(keyFor(uuid, revision), null, 'session');
    if (typeof stored === 'string' && stored !== '') {
        return stored;
    }
    const key = newId();
    writeJson(keyFor(uuid, revision), key, 'session');
    return key;
}

/** Forgets the key (after success, or on `idempotency_key_reused` to retry with a fresh one). */
export function clearIdempotencyKey(uuid: string, revision: number): void {
    removeKey(keyFor(uuid, revision), 'session');
}

const sendKeyFor = (uuid: string): string => `idem.send.${uuid}`;

interface StoredSendKey {
    readonly fingerprint: string;
    readonly key: string;
}

function isStoredSendKey(value: unknown): value is StoredSendKey {
    if (typeof value !== 'object' || value === null) {
        return false;
    }
    const record = value as Record<string, unknown>;
    return typeof record.fingerprint === 'string' && typeof record.key === 'string';
}

/** Same key while the request body (`fingerprint`) is unchanged; another recipient → new key. */
export function getSendIdempotencyKey(uuid: string, fingerprint: string): string {
    const stored = readJson<unknown>(sendKeyFor(uuid), null, 'session');
    if (isStoredSendKey(stored) && stored.fingerprint === fingerprint) {
        return stored.key;
    }
    const key = newId();
    writeJson(sendKeyFor(uuid), { fingerprint, key } satisfies StoredSendKey, 'session');
    return key;
}

/** Forgets the send key (after success, or on `idempotency_key_reused`). */
export function clearSendIdempotencyKey(uuid: string): void {
    removeKey(sendKeyFor(uuid), 'session');
}
