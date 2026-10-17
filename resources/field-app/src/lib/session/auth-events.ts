/** "Session lost" notifications (reauth or wipe) from anywhere in the app → router redirect. */
import { DeviceWipedError, ReauthRequiredError, isAuthLost } from '../api/errors.ts';
import { markSessionReauth } from './session-cache.ts';

export type AuthNotice = 'reauth' | 'wiped' | 'logout';
type Listener = (notice: AuthNotice | undefined) => void;

const listeners = new Set<Listener>();
const RANK: Readonly<Record<AuthNotice, number>> = { reauth: 1, logout: 2, wiped: 3 };
let strongest: AuthNotice | undefined;

export function onAuthLost(listener: Listener): () => void {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}

/** Login notice for an auth error: none when this device never had a session. */
export function noticeFor(error: unknown): AuthNotice | undefined {
    if (error instanceof DeviceWipedError) {
        return 'wiped';
    }
    if (error instanceof ReauthRequiredError && !error.expired) {
        return undefined;
    }
    return 'reauth';
}

/**
 * Keeps the strongest notice since the last login (wiped > logout > reauth): a later, weaker auth
 * error (e.g. "unregistered" after the wipe removed the device) must not replace it.
 */
export function rememberNotice(notice: AuthNotice | undefined): AuthNotice | undefined {
    if (notice !== undefined && (strongest === undefined || RANK[notice] > RANK[strongest])) {
        strongest = notice;
    }
    return strongest;
}

/** A successful login starts over. */
export function resetAuthNotice(): void {
    strongest = undefined;
}

/** The cached session may no longer open the app offline (offline-start.ts); rows stay. */
export function rememberReauth(error: unknown): void {
    if (error instanceof ReauthRequiredError) {
        markSessionReauth().catch((failure: unknown) => {
            console.warn('Marking the session for sign-in failed', failure);
        });
    }
}

/** Emits when `error` is a `ReauthRequiredError`/`DeviceWipedError`; returns whether it did. */
export function reportAuthError(error: unknown): boolean {
    if (!isAuthLost(error)) {
        return false;
    }
    rememberReauth(error);
    const notice = rememberNotice(noticeFor(error));
    for (const listener of listeners) {
        listener(notice);
    }
    return true;
}
