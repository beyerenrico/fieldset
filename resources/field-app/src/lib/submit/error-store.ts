/** Server validation errors of the last submit attempt, per submission (shown in fill + review). */
import type { ValidationDetail } from '../api/errors.ts';

const EMPTY: readonly ValidationDetail[] = [];
const store = new Map<string, readonly ValidationDetail[]>();
const listeners = new Set<() => void>();

export function getSubmitErrors(uuid: string): readonly ValidationDetail[] {
    return store.get(uuid) ?? EMPTY;
}

export function setSubmitErrors(uuid: string, errors: readonly ValidationDetail[]): void {
    if (errors.length === 0 && !store.has(uuid)) {
        return;
    }
    if (errors.length === 0) {
        store.delete(uuid);
    } else {
        store.set(uuid, errors);
    }
    for (const listener of listeners) {
        listener();
    }
}

export function subscribeSubmitErrors(listener: () => void): () => void {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}
