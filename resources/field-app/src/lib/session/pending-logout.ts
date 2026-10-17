/**
 * A sign-out the server has not confirmed yet (offline or failed `POST /auth/logout`). The marker
 * survives the purge (local-data.ts keeps it), so the session and the remember-me cookie are ended as
 * soon as the server can be reached. While it is set the app counts as signed out: the route guard
 * sends to the login page without asking `/me` with the old cookies.
 */
import { logout } from '../api/endpoints.ts';
import { ApiError } from '../api/errors.ts';
import { readJson, removeKey, writeJson } from '../storage/local.ts';

export const PENDING_LOGOUT_KEY = 'pendingLogout';

const listeners = new Set<() => void>();
let running: Promise<boolean> | null = null;

function notify(): void {
    for (const listener of listeners) {
        listener();
    }
}

export function hasPendingLogout(): boolean {
    return readJson<unknown>(PENDING_LOGOUT_KEY, false) === true;
}

export function markPendingLogout(): void {
    writeJson(PENDING_LOGOUT_KEY, true);
    notify();
}

/** The server ended the session, or a new sign-in replaced it. */
export function clearPendingLogout(): void {
    removeKey(PENDING_LOGOUT_KEY);
    notify();
}

export function subscribePendingLogout(listener: () => void): () => void {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}

function isOnline(): boolean {
    return typeof navigator === 'undefined' || navigator.onLine;
}

/** A 4xx answer (e.g. 401: no session left) ends the attempt too; network errors and 5xx keep it. */
async function attempt(): Promise<boolean> {
    try {
        await logout();
    } catch (error) {
        if (!(error instanceof ApiError) || error.status >= 500) {
            return false;
        }
    }
    clearPendingLogout();
    return true;
}

/**
 * Sends the pending `POST /auth/logout` when online. Resolves with true once nothing is pending.
 * Concurrent callers share one request.
 */
export function flushPendingLogout(): Promise<boolean> {
    if (!hasPendingLogout()) {
        return Promise.resolve(true);
    }
    if (!isOnline()) {
        return Promise.resolve(false);
    }
    running ??= attempt().finally(() => {
        running = null;
    });
    return running;
}

/** Retries on app start and whenever the browser comes back online; returns a stop function. */
export function startPendingLogoutSync(): () => void {
    const run = (): void => {
        void flushPendingLogout();
    };
    window.addEventListener('online', run);
    run();
    return () => {
        window.removeEventListener('online', run);
    };
}
