/** Minimal observable value for useSyncExternalStore (PWA state lives outside React). */
export interface Store<T> {
    readonly get: () => T;
    readonly set: (next: T) => void;
    readonly update: (change: (current: T) => T) => void;
    readonly subscribe: (listener: () => void) => () => void;
}

export function createStore<T>(initial: T): Store<T> {
    let value = initial;
    const listeners = new Set<() => void>();
    const set = (next: T): void => {
        if (Object.is(next, value)) {
            return;
        }
        value = next;
        for (const listener of listeners) {
            listener();
        }
    };
    return {
        get: () => value,
        set,
        update: (change) => {
            set(change(value));
        },
        subscribe: (listener) => {
            listeners.add(listener);
            return () => {
                listeners.delete(listener);
            };
        },
    };
}
