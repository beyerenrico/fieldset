/**
 * One `Idempotency-Key` per (submission, revision) for submit, per (submission, recipient) for
 * "send to customer" and per (task, decision) for reviews, kept in sessionStorage so retries and
 * reloads replay the same key; a new revision, recipient or decision gets a new key.
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

interface StoredKey {
    readonly fingerprint: string;
    readonly key: string;
}

function isStoredKey(value: unknown): value is StoredKey {
    if (typeof value !== 'object' || value === null) {
        return false;
    }
    const record = value as Record<string, unknown>;
    return typeof record.fingerprint === 'string' && typeof record.key === 'string';
}

/** Same key while the request body (`fingerprint`) is unchanged; another body → new key. */
function fingerprintKey(storageKey: string, fingerprint: string): string {
    const stored = readJson<unknown>(storageKey, null, 'session');
    if (isStoredKey(stored) && stored.fingerprint === fingerprint) {
        return stored.key;
    }
    const key = newId();
    writeJson(storageKey, { fingerprint, key } satisfies StoredKey, 'session');
    return key;
}

const sendKeyFor = (uuid: string): string => `idem.send.${uuid}`;

/** Same key while the request body (`fingerprint`) is unchanged; another recipient → new key. */
export function getSendIdempotencyKey(uuid: string, fingerprint: string): string {
    return fingerprintKey(sendKeyFor(uuid), fingerprint);
}

/** Forgets the send key (after success, or on `idempotency_key_reused`). */
export function clearSendIdempotencyKey(uuid: string): void {
    removeKey(sendKeyFor(uuid), 'session');
}

const taskKeyFor = (taskId: number): string => `idem.task.${String(taskId)}`;

/** One key per (task, decision + comment): a retry of the same decision replays. */
export function getTaskIdempotencyKey(taskId: number, fingerprint: string): string {
    return fingerprintKey(taskKeyFor(taskId), fingerprint);
}

/** Forgets the task key (after success, a closed task, or `idempotency_key_reused`). */
export function clearTaskIdempotencyKey(taskId: number): void {
    removeKey(taskKeyFor(taskId), 'session');
}
