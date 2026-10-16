/** Clearing local data (logout, wipe). Other modules register hooks, e.g. the query cache. */
import { getDeviceRecord, saveDeviceRecord } from '../device/credentials.ts';
import { clearAllTables } from '../db/db.ts';
import { clearAppKeys } from '../storage/local.ts';
import { reloadOrganizationId } from './org-store.ts';

type Hook = () => void | Promise<void>;

const hooks = new Set<Hook>();

/** UI preferences survive logout and wipe; they hold no personal data. */
export const PRESERVED_KEYS: readonly string[] = ['settings'];

export function onLocalDataCleared(hook: Hook): () => void {
    hooks.add(hook);
    return () => {
        hooks.delete(hook);
    };
}

export interface ClearOptions {
    /** Logout keeps the device uuid (not the secret) so the next login rotates the same device. */
    readonly keepDeviceId: boolean;
}

export async function clearLocalData(options: ClearOptions): Promise<void> {
    const device = options.keepDeviceId ? await getDeviceRecord() : undefined;
    await clearAllTables();
    clearAppKeys(PRESERVED_KEYS);
    reloadOrganizationId();
    if (device !== undefined) {
        await saveDeviceRecord({ deviceUuid: device.deviceUuid, secret: null });
    }
    for (const hook of hooks) {
        await hook();
    }
}
