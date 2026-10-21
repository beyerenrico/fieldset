/** Namespaced localStorage/sessionStorage helpers (all app keys start with `fieldset.`). */
export const PREFIX = 'fieldset.';

function storage(kind: 'local' | 'session'): Storage | null {
    try {
        return kind === 'local' ? window.localStorage : window.sessionStorage;
    } catch {
        return null;
    }
}

export function readJson<T>(key: string, fallback: T, kind: 'local' | 'session' = 'local'): T {
    const raw = storage(kind)?.getItem(PREFIX + key);
    if (raw === null || raw === undefined) {
        return fallback;
    }
    try {
        return JSON.parse(raw) as T;
    } catch {
        return fallback;
    }
}

export function writeJson(key: string, value: unknown, kind: 'local' | 'session' = 'local'): void {
    try {
        storage(kind)?.setItem(PREFIX + key, JSON.stringify(value));
    } catch {
        // Quota or private mode: preferences are best-effort.
    }
}

export function removeKey(key: string, kind: 'local' | 'session' = 'local'): void {
    storage(kind)?.removeItem(PREFIX + key);
}

/** Removes every app key except the listed ones (keys without prefix). */
export function clearAppKeys(keep: readonly string[] = []): void {
    for (const kind of ['local', 'session'] as const) {
        const store = storage(kind);
        if (store === null) {
            continue;
        }
        const keys = Array.from({ length: store.length }, (_, index) => store.key(index));
        for (const key of keys) {
            if (key?.startsWith(PREFIX) === true && !keep.includes(key.slice(PREFIX.length))) {
                store.removeItem(key);
            }
        }
    }
}
