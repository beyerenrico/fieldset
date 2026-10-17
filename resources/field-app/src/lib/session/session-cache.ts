/**
 * The last `GET /me` answer in meta 'session' (ADR 0035): the app can start offline from it. It is
 * written on every successful fetch (which also clears a reauth mark) and removed with the other
 * tables on logout and wipe.
 */
import type { Me } from '../api/types.ts';
import { db } from '../db/db.ts';
import type { SessionMeta } from '../db/schema.ts';

function isSessionMeta(value: unknown): value is SessionMeta {
    if (typeof value !== 'object' || value === null) {
        return false;
    }
    const record = value as Record<string, unknown>;
    const me = record.me as Record<string, unknown> | null | undefined;
    return (
        typeof record.userId === 'number' &&
        typeof record.savedAt === 'number' &&
        typeof me === 'object' &&
        me !== null &&
        typeof me.user === 'object' &&
        Array.isArray(me.organizations)
    );
}

export async function saveSession(me: Me, now: number = Date.now()): Promise<void> {
    const value: SessionMeta = { userId: me.user.id, me, savedAt: now };
    await db.meta.put({ key: 'session', value });
}

/** The cached session, or undefined when none (or an unreadable one) is stored. */
export async function loadSession(): Promise<SessionMeta | undefined> {
    try {
        const value: unknown = (await db.meta.get('session'))?.value;
        return isSessionMeta(value) ? value : undefined;
    } catch {
        return undefined;
    }
}

/**
 * The server ended the session (reauth): keep every row, but an offline start must not open the
 * cached session any more. A fresh `/me` (login) stores a new, unmarked session.
 */
export async function markSessionReauth(): Promise<void> {
    await db.transaction('rw', db.meta, async () => {
        const value: unknown = (await db.meta.get('session'))?.value;
        if (isSessionMeta(value) && value.reauth !== true) {
            const marked: SessionMeta = { ...value, reauth: true };
            await db.meta.put({ key: 'session', value: marked });
        }
    });
}

/** Forgets the cached session (after a sign-in whose `me` could not be loaded). */
export async function forgetSession(): Promise<void> {
    await db.meta.delete('session');
}
