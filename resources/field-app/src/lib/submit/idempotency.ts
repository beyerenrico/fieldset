/**
 * One `Idempotency-Key` per (submission, revision), kept in sessionStorage so retries and reloads
 * replay the same key; a new revision gets a new key.
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
