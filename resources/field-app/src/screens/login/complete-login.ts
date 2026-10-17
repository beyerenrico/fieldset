/**
 * What happens after the credentials (and 2FA) were accepted (§9.3, ADR 0035 "Reauth"): register the
 * device, start with an empty query cache, load `me` (stores the session, sets the current user),
 * ask for persistent storage, and look for unsynced rows of another user. Nothing local is cleared:
 * a reauth resumes the outbox, so the caller requests a sync once it continues.
 */
import type { QueryClient } from '@tanstack/react-query';
import { meQueryOptions } from '../../hooks/queries.ts';
import type { Me } from '../../lib/api/types.ts';
import { ensureDeviceRegistered } from '../../lib/device/register.ts';
import { disposeDraftSessions } from '../../lib/drafts/draft-session.ts';
import { resetAuthNotice } from '../../lib/session/auth-events.ts';
import { fetchFreshMe } from '../../lib/session/offline-start.ts';
import { forgetSession } from '../../lib/session/session-cache.ts';
import { countForeignRows } from '../../lib/session/unsynced.ts';
import { persist } from '../../lib/storage/persistence.ts';

export interface LoginOutcome {
    /** The signed-in user's id, or null when `/me` failed (the route guard deals with it). */
    readonly userId: number | null;
    /** Unsynced drafts, ops and attachments of other users on this device. */
    readonly foreignRows: number;
}

/**
 * Loads `me` from the server (no cached fallback). On failure the cached session is dropped: it may
 * belong to the previous user, and an offline start must never pair it with this user's cookie.
 */
async function loadMe(queryClient: QueryClient): Promise<Me | null> {
    try {
        const me = await fetchFreshMe();
        queryClient.setQueryData(meQueryOptions().queryKey, me);
        return me;
    } catch (error) {
        console.warn('Loading the session after login failed', error);
        await forgetSession().catch(() => undefined);
        return null;
    }
}

export async function completeLogin(queryClient: QueryClient): Promise<LoginOutcome> {
    try {
        await ensureDeviceRegistered();
    } catch (error) {
        // Login still works; device-status recovery is unavailable until the next login.
        console.warn('Device registration failed', error);
    }
    // Nothing cached or open from a previous session may reach this user.
    resetAuthNotice();
    disposeDraftSessions();
    queryClient.clear();
    const me = await loadMe(queryClient);
    void persist(); // §9.2: ask the browser to keep the offline store (no-op once granted).
    if (me === null) {
        return { userId: null, foreignRows: 0 };
    }
    const foreignRows = await countForeignRows(me.user.id).catch(() => 0);
    return { userId: me.user.id, foreignRows };
}
