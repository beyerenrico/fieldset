/**
 * Sync requests (ADR 0035). Anything that wants a sync calls requestSync(); the sync engine and the
 * catalog cache listen. Requests reach the other tabs through a BroadcastChannel; each tab's engine
 * then takes the cross-tab lock, so only one of them runs.
 */
export type SyncReason =
    'start' | 'online' | 'visible' | 'enqueue' | 'manual' | 'login' | 'interval' | 'sw';

const REASONS: ReadonlySet<string> = new Set<SyncReason>([
    'start',
    'online',
    'visible',
    'enqueue',
    'manual',
    'login',
    'interval',
    'sw',
]);

export const SYNC_CHANNEL = 'fsa-sync';

type Listener = (reason: SyncReason) => void;

const listeners = new Set<Listener>();
let channel: BroadcastChannel | null | undefined;

function isReason(value: unknown): value is SyncReason {
    return typeof value === 'string' && REASONS.has(value);
}

function notify(reason: SyncReason): void {
    for (const listener of listeners) {
        listener(reason);
    }
}

function getChannel(): BroadcastChannel | null {
    if (channel !== undefined) {
        return channel;
    }
    if (typeof BroadcastChannel === 'undefined') {
        channel = null;
        return channel;
    }
    channel = new BroadcastChannel(SYNC_CHANNEL);
    channel.onmessage = (event: MessageEvent<unknown>) => {
        if (isReason(event.data)) {
            notify(event.data);
        }
    };
    return channel;
}

/** Asks for a sync in this tab and in every other open tab. */
export function requestSync(reason: SyncReason): void {
    getChannel()?.postMessage(reason);
    notify(reason);
}

export function onSyncRequested(listener: Listener): () => void {
    getChannel();
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}

/** Tests only: forget listeners and close the channel. */
export function resetSyncTriggersForTests(): void {
    listeners.clear();
    channel?.close();
    channel = undefined;
}
