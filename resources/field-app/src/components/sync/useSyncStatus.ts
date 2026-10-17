/** Live sync state of the signed-in user for the header badge and Settings (ADR 0035). */
import { useMemo } from 'react';
import { useOnline } from '../../hooks/useOnline.ts';
import { useSession } from '../../hooks/useSession.ts';
import { db } from '../../lib/db/db.ts';
import { useLiveQuery } from '../../lib/db/live.ts';
import type { SyncMeta } from '../../lib/db/schema.ts';
import { unsyncedForUser, type UnsyncedTotals } from '../../lib/session/unsynced.ts';

function isSyncMeta(value: unknown): value is SyncMeta {
    return typeof value === 'object' && value !== null && 'state' in value;
}

/** Unsynced items over every organization of the user (logout would remove all of them). */
export function useUnsyncedTotals(): UnsyncedTotals | undefined {
    const { me } = useSession();
    const userId = me.user.id;
    const orgIds = useMemo(
        () => me.organizations.map((organization) => organization.id),
        [me.organizations],
    );
    return useLiveQuery(() => unsyncedForUser(userId, orgIds), [userId, orgIds.join(',')]);
}

/** meta 'sync' as written by the sync engine; null before its first run. */
export function useSyncMeta(): SyncMeta | null | undefined {
    return useLiveQuery(async () => {
        const value: unknown = (await db.meta.get('sync'))?.value;
        return isSyncMeta(value) ? value : null;
    }, []);
}

export type Connection = 'online' | 'offline' | 'unreachable';

/**
 * The browser's network state, corrected by the last sync run: a network that reaches nothing
 * ("lie-fi") counts as `unreachable` until the server answers again.
 */
export function useConnection(): Connection {
    const online = useOnline();
    const sync = useSyncMeta();
    if (!online) {
        return 'offline';
    }
    return sync?.state === 'offline' ? 'unreachable' : 'online';
}
