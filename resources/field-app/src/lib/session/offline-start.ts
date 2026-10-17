/**
 * `GET /me` with an offline fallback (ADR 0035): every successful answer is stored in meta 'session'
 * and sets the current user of the offline store. When the server cannot be reached and a cached
 * session exists, the app starts from it; 401/419 still run the device-status flow (reauth, wipe).
 */
import { fetchMe } from '../api/endpoints.ts';
import { ApiError, NetworkError } from '../api/errors.ts';
import type { Me } from '../api/types.ts';
import { setCurrentUserId } from '../db/scope.ts';
import { loadSession, saveSession } from './session-cache.ts';

/** On a hanging connection ("lie-fi") a cached session wins after this long. */
export const ME_TIMEOUT_MS = 8000;

/** Gateway answers while the app server is down count as "unreachable", like a failed fetch. */
const UNREACHABLE = new Set([502, 503, 504]);

/** `me` objects served from the cache: the query treats them as stale, so it refetches when it can. */
const fromCache = new WeakSet<Me>();

type SessionListener = (me: Me) => void;
const listeners = new Set<SessionListener>();

export function isUnreachable(error: unknown): boolean {
    return (
        error instanceof NetworkError ||
        (error instanceof ApiError && UNREACHABLE.has(error.status))
    );
}

export function isCachedSession(me: Me | undefined): boolean {
    return me !== undefined && fromCache.has(me);
}

/** A fresh `me` arrived outside the query (e.g. after 403 organization_forbidden). */
export function onSessionRefreshed(listener: SessionListener): () => void {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}

async function remember(me: Me): Promise<Me> {
    setCurrentUserId(me.user.id);
    try {
        await saveSession(me);
    } catch (error) {
        console.warn('Saving the session failed', error);
    }
    return me;
}

/** Stores a fresh `me`, makes its user the current one and tells the listeners. */
export async function adoptSession(me: Me): Promise<void> {
    await remember(me);
    for (const listener of listeners) {
        listener(me);
    }
}

function adoptCached(me: Me, userId: number): Me {
    setCurrentUserId(userId);
    fromCache.add(me);
    return me;
}

/** Resolves with the request's `me`, or with null when `ms` passes first. */
async function withDeadline(request: Promise<Me>, ms: number): Promise<Me | null> {
    let timer: ReturnType<typeof setTimeout> | undefined;
    const deadline = new Promise<null>((resolve) => {
        timer = setTimeout(() => {
            resolve(null);
        }, ms);
    });
    try {
        return await Promise.race([request, deadline]);
    } finally {
        clearTimeout(timer);
    }
}

/**
 * `me` from the server only, never from the cache: right after a sign-in the cookie may belong to a
 * different user than the cached session.
 */
export function fetchFreshMe(): Promise<Me> {
    return fetchMe().then(remember);
}

/** The query function of `['me']`. */
export async function fetchSessionMe(): Promise<Me> {
    const cached = await loadSession();
    const request = fetchFreshMe();
    if (cached === undefined) {
        return request;
    }
    try {
        const me = await withDeadline(request, ME_TIMEOUT_MS);
        if (me !== null) {
            return me;
        }
        request.catch(() => undefined); // Settles later; the next refetch reports its outcome.
    } catch (error) {
        if (!isUnreachable(error)) {
            throw error;
        }
    }
    return adoptCached(cached.me, cached.userId);
}
