/**
 * Background Sync is only a Chromium wake-up (§9.2, ADR 0035): the service worker's `sync` handler
 * posts to open clients, which then run their own engine. Safari and Firefox have no SyncManager.
 */
export const BACKGROUND_SYNC_TAG = 'fsa-outbox';

interface SyncManagerLike {
    register(tag: string): Promise<void>;
}

function syncManagerOf(
    registration: ServiceWorkerRegistration | undefined,
): SyncManagerLike | null {
    const manager = (registration as { sync?: unknown } | undefined)?.sync;
    return typeof manager === 'object' &&
        manager !== null &&
        typeof (manager as SyncManagerLike).register === 'function'
        ? (manager as SyncManagerLike)
        : null;
}

/** Registers the tag when a service worker with Background Sync exists; never throws. */
export async function registerBackgroundSync(): Promise<boolean> {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
        return false;
    }
    try {
        // getRegistration() resolves undefined without a worker (`ready` would never resolve).
        const manager = syncManagerOf(await navigator.serviceWorker.getRegistration());
        if (manager === null) {
            return false;
        }
        await manager.register(BACKGROUND_SYNC_TAG);
        return true;
    } catch {
        return false; // permission denied or no active worker: the app-side triggers still run
    }
}
