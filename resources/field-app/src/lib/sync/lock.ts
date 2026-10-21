/**
 * Single flight across tabs (ADR 0035): the Web Lock `fieldset-sync`, requested with ifAvailable, so a
 * tab whose request finds the lock taken skips the run. Without Web Locks, a mutex in this tab.
 */
export const SYNC_LOCK = 'fieldset-sync';

export interface LockManagerLike {
    request<T>(
        name: string,
        options: { ifAvailable: boolean },
        callback: (lock: unknown) => Promise<T>,
    ): Promise<T>;
}

/** The browser's Web Locks, or null where they are missing (older Safari, jsdom). */
export function lockManager(): LockManagerLike | null {
    const locks = (globalThis.navigator as { locks?: unknown } | undefined)?.locks;
    return typeof locks === 'object' &&
        locks !== null &&
        typeof (locks as LockManagerLike).request === 'function'
        ? (locks as LockManagerLike)
        : null;
}

let held = false;

/** Runs `task` while holding the lock; resolves null (task not run) when someone else holds it. */
export async function withSyncLock<T>(task: () => Promise<T>): Promise<T | null> {
    const locks = lockManager();
    if (locks !== null) {
        return locks.request(SYNC_LOCK, { ifAvailable: true }, (lock) =>
            lock === null ? Promise.resolve(null) : task(),
        );
    }
    if (held) {
        return null;
    }
    held = true;
    try {
        return await task();
    } finally {
        held = false;
    }
}
