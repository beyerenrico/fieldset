/** "Session lost" notifications (reauth or wipe) from anywhere in the app → router redirect. */
import { DeviceWipedError, ReauthRequiredError, isAuthLost } from '../api/errors.ts';

export type AuthNotice = 'reauth' | 'wiped' | 'logout';
type Listener = (notice: AuthNotice | undefined) => void;

const listeners = new Set<Listener>();

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

/** Emits when `error` is a `ReauthRequiredError`/`DeviceWipedError`; returns whether it did. */
export function reportAuthError(error: unknown): boolean {
    if (!isAuthLost(error)) {
        return false;
    }
    const notice = noticeFor(error);
    for (const listener of listeners) {
        listener(notice);
    }
    return true;
}
