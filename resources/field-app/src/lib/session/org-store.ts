/** Selected organization (sent as `X-Organization-Id`), persisted per install, observable. */
import { readJson, removeKey, writeJson } from '../storage/local.ts';

const KEY = 'org';
const listeners = new Set<() => void>();

let current: number | null = readJson<number | null>(KEY, null);

function notify(): void {
    for (const listener of listeners) {
        listener();
    }
}

export function getOrganizationId(): number | null {
    return current;
}

export function setOrganizationId(id: number | null): void {
    current = id;
    if (id === null) {
        removeKey(KEY);
    } else {
        writeJson(KEY, id);
    }
    notify();
}

/** Re-reads the persisted value (after a wipe or in tests). */
export function reloadOrganizationId(): void {
    current = readJson<number | null>(KEY, null);
    notify();
}

export function subscribeOrganization(listener: () => void): () => void {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}
