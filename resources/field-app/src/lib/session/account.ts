/**
 * Ending the account on this device (ADR 0035 "Logout, wipe, reauth"): wipe, plus the cross-tab
 * announcement so other open tabs drop what they hold in memory and reload to the login page.
 * Logout lives in sign-out.ts (it calls the API; this module must stay importable from device-status).
 */
import type { AuthNotice } from './auth-events.ts';
import { endSession, purgeDevice } from './local-data.ts';
import { LOGIN_PATH, hardReload } from './reload.ts';

export const ACCOUNT_CHANNEL = 'fieldset-account';

export type AccountEvent = 'logout' | 'wiped';

type Listener = (event: AccountEvent) => void;

interface AccountMessage {
    readonly event: AccountEvent;
    /** The sending tab: BroadcastChannel also reaches other channel objects of the same tab. */
    readonly tab: string;
}

const TAB_ID = Math.random().toString(36).slice(2);

function isForeignMessage(value: unknown): value is AccountMessage {
    if (typeof value !== 'object' || value === null) {
        return false;
    }
    const record = value as Record<string, unknown>;
    return (record.event === 'logout' || record.event === 'wiped') && record.tab !== TAB_ID;
}

export function loginUrl(notice: AuthNotice): string {
    return `${LOGIN_PATH}?notice=${notice}`;
}

/** Tells every other tab of this origin that the account data is gone. */
export function announceAccountEvent(event: AccountEvent): void {
    if (typeof BroadcastChannel === 'undefined') {
        return;
    }
    const channel = new BroadcastChannel(ACCOUNT_CHANNEL);
    const message: AccountMessage = { event, tab: TAB_ID };
    channel.postMessage(message);
    channel.close();
}

/** Another tab signed out or wiped this device. */
export function onAccountEvent(listener: Listener): () => void {
    if (typeof BroadcastChannel === 'undefined') {
        return () => undefined;
    }
    const channel = new BroadcastChannel(ACCOUNT_CHANNEL);
    channel.onmessage = (message: MessageEvent<unknown>) => {
        if (isForeignMessage(message.data)) {
            listener(message.data.event);
        }
    };
    return () => {
        channel.close();
    };
}

/**
 * `device-status` answered `wipe` (§9.3): remove everything including the device uuid, without a
 * warning and without syncing, then reload to the login page with the "wiped" notice. The other
 * tabs hear it first, so none of them writes or sends while the data is removed.
 */
export async function wipeDevice(): Promise<void> {
    announceAccountEvent('wiped');
    await endSession();
    await purgeDevice({ keepDeviceId: false });
    hardReload(loginUrl('wiped'));
}
