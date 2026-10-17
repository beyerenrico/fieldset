/**
 * Clearing local data (logout, wipe; ADR 0035 "Logout, wipe, reauth"). Other modules register hooks,
 * e.g. the query cache. `purgeDevice()` also removes Cache Storage and the service worker.
 */
import { getDeviceRecord, saveDeviceRecord } from '../device/credentials.ts';
import { clearAllTables } from '../db/db.ts';
import { setCurrentUserId } from '../db/scope.ts';
import { clearAppKeys } from '../storage/local.ts';
import { reloadOrganizationId } from './org-store.ts';

type Hook = () => void | Promise<void>;

const hooks = new Set<Hook>();
const beforeHooks = new Set<Hook>();

/** UI preferences survive logout and wipe; they hold no personal data. */
export const PRESERVED_KEYS: readonly string[] = ['settings', 'pwa.installDismissed'];

export function onLocalDataCleared(hook: Hook): () => void {
    hooks.add(hook);
    return () => {
        hooks.delete(hook);
    };
}

/**
 * Runs before anything is removed, e.g. to end open drafts without saving: a flush that ran after the
 * tables were emptied would write personal data back.
 */
export function onLocalDataClearing(hook: Hook): () => void {
    beforeHooks.add(hook);
    return () => {
        beforeHooks.delete(hook);
    };
}

async function runHooks(set: ReadonlySet<Hook>): Promise<void> {
    for (const hook of set) {
        await hook();
    }
}

export interface ClearOptions {
    /** Logout keeps the device uuid (not the secret) so the next login rotates the same device. */
    readonly keepDeviceId: boolean;
}

/**
 * Empties every Dexie table, removes every `fsa.*` key but `fsa.settings` (local and session storage),
 * forgets the signed-in user and keeps `meta.device` with the uuid only when asked to.
 */
export async function clearLocalData(options: ClearOptions): Promise<void> {
    await runHooks(beforeHooks);
    const device = options.keepDeviceId ? await getDeviceRecord() : undefined;
    await clearAllTables();
    clearAppKeys(PRESERVED_KEYS);
    reloadOrganizationId();
    setCurrentUserId(null);
    if (device !== undefined) {
        await saveDeviceRecord({ deviceUuid: device.deviceUuid, secret: null });
    }
    await runHooks(hooks);
}

/** Deletes every Cache Storage cache of this origin (precache and runtime caches). */
export async function deleteAllCaches(): Promise<void> {
    if (typeof caches === 'undefined') {
        return;
    }
    try {
        const keys = await caches.keys();
        await Promise.all(keys.map((key) => caches.delete(key)));
    } catch (error) {
        console.warn('Deleting caches failed', error);
    }
}

/** Unregisters every service worker of this origin; the next page load registers a fresh one. */
export async function unregisterServiceWorkers(): Promise<void> {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }
    try {
        const registrations = await navigator.serviceWorker.getRegistrations();
        await Promise.all(registrations.map((registration) => registration.unregister()));
    } catch (error) {
        console.warn('Unregistering the service worker failed', error);
    }
}

/** Everything logout and wipe remove from this device (IndexedDB, storage, caches, service worker). */
export async function purgeDevice(options: ClearOptions): Promise<void> {
    await clearLocalData(options);
    await deleteAllCaches();
    await unregisterServiceWorkers();
}
