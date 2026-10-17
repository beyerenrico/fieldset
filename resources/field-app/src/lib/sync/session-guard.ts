/**
 * Who may sync (ADR 0035): this tab's signed-in user must be the user of the last login, which
 * meta 'session' records for every tab (IndexedDB is shared). A tab left open from a lost session
 * thus never sends its user's outbox under the cookie of another user signed in elsewhere, and a
 * tab whose session was ended (logout or wipe in another tab: meta 'session' is gone) sends nothing.
 */
import { db } from '../db/db.ts';
import { getCurrentUserId } from '../db/scope.ts';

/** The user id meta 'session' holds (read inside the caller's transaction when there is one). */
export async function sessionUserId(): Promise<number | null> {
    const value: unknown = (await db.meta.get('session'))?.value;
    if (typeof value !== 'object' || value === null || !('userId' in value)) {
        return null;
    }
    return typeof value.userId === 'number' ? value.userId : null;
}

/** True when `userId` is this tab's user and the last signed-in user. */
export async function isCurrentSession(userId: number): Promise<boolean> {
    return getCurrentUserId() === userId && (await sessionUserId()) === userId;
}
