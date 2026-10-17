/**
 * Logout (ADR 0035 "Logout, wipe, reauth", §9.3). The caller warns about unsynced items first
 * (unsynced.ts) and offers "Sync now"; this removes everything but the device uuid and reloads.
 */
import { announceAccountEvent, loginUrl } from './account.ts';
import { endSession, purgeDevice } from './local-data.ts';
import { flushPendingLogout, markPendingLogout } from './pending-logout.ts';
import { hardReload } from './reload.ts';

/** A hanging connection must not hold the purge back; the marker retries the request later. */
export const LOGOUT_WAIT_MS = 5000;

export interface SignOutResult {
    /** False offline: the page stays (a reload would show the browser's offline page). */
    readonly reloading: boolean;
}

async function logoutWithin(ms: number): Promise<void> {
    let timer: ReturnType<typeof setTimeout> | undefined;
    const deadline = new Promise<void>((resolve) => {
        timer = setTimeout(resolve, ms);
    });
    try {
        await Promise.race([flushPendingLogout(), deadline]);
    } finally {
        clearTimeout(timer);
    }
}

/**
 * 1. Mark the sign-out as pending (kept until the server confirms it), 2. tell the other tabs,
 * forget the user and stop syncing, 3. POST /auth/logout while the other tabs dispose their drafts,
 * 4. clear every table but `meta.device` (uuid only), the app's storage keys but the preserved ones,
 * Cache Storage and the service worker, 5. reload to the login page when online.
 */
export async function signOut(): Promise<SignOutResult> {
    markPendingLogout();
    announceAccountEvent('logout');
    await Promise.all([endSession(), logoutWithin(LOGOUT_WAIT_MS)]);
    await purgeDevice({ keepDeviceId: true });
    const reloading = typeof navigator === 'undefined' || navigator.onLine;
    if (reloading) {
        hardReload(loginUrl('logout'));
    }
    return { reloading };
}
