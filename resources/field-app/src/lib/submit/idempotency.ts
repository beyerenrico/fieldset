/**
 * One `Idempotency-Key` per (task, decision) for reviews, kept in sessionStorage so retries and
 * reloads replay the same key; another decision gets a new key. Submit and "send to customer" keys
 * are persisted with their request in the outbox instead (ADR 0035).
 */
import { newId } from '../ids.ts';
import { readJson, removeKey, writeJson } from '../storage/local.ts';

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

const taskKeyFor = (taskId: number): string => `idem.task.${String(taskId)}`;

/** One key per (task, decision + comment): a retry of the same decision replays. */
export function getTaskIdempotencyKey(taskId: number, fingerprint: string): string {
    return fingerprintKey(taskKeyFor(taskId), fingerprint);
}

/** Forgets the task key (after success, a closed task, or `idempotency_key_reused`). */
export function clearTaskIdempotencyKey(taskId: number): void {
    removeKey(taskKeyFor(taskId), 'session');
}
