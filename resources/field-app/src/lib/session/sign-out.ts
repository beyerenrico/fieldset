/**
 * Logout (ADR 0035 "Logout, wipe, reauth", §9.3). The caller warns about unsynced items first
 * (unsynced.ts) and offers "Sync now"; this removes everything but the device uuid and reloads.
 */
import { logout } from '../api/endpoints.ts';
import { announceAccountEvent, loginUrl } from './account.ts';
import { purgeDevice } from './local-data.ts';
import { hardReload } from './reload.ts';

export interface SignOutResult {
    /** False offline: the page stays (a reload would show the browser's offline page). */
    readonly reloading: boolean;
}

/**
 * 1. POST /auth/logout (best effort), 2. clear every table but `meta.device` (uuid only), the app's
 * storage keys but `fsa.settings`, Cache Storage and the service worker, 3. tell the other tabs,
 * 4. reload to the login page when online.
 */
export async function signOut(): Promise<SignOutResult> {
    try {
        await logout();
    } catch {
        // Offline or already signed out: local data is cleared anyway.
    }
    await purgeDevice({ keepDeviceId: true });
    announceAccountEvent('logout');
    const reloading = typeof navigator === 'undefined' || navigator.onLine;
    if (reloading) {
        hardReload(loginUrl('logout'));
    }
    return { reloading };
}
