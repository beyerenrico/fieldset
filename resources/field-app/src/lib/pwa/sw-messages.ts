/**
 * Background Sync as a Chromium-only wake-up (ADR 0035, §9.2): the outbox registers the tag while
 * offline (src/lib/outbox/background-sync.ts), public/sw-sync.js posts `{ type: 'fsa-sync' }` to the
 * open tabs on the `sync` event, and each tab turns that into a sync request for its engine.
 */
import { requestSync } from '../sync/triggers.ts';

/** Must match public/sw-sync.js and the outbox's registration. */
export const BACKGROUND_SYNC_TAG = 'fsa-outbox';
export const SW_SYNC_MESSAGE = 'fsa-sync';

type MessageSource = Pick<EventTarget, 'addEventListener' | 'removeEventListener'>;

function isSyncMessage(data: unknown): boolean {
    return (
        typeof data === 'object' &&
        data !== null &&
        (data as { type?: unknown }).type === SW_SYNC_MESSAGE
    );
}

/** Forwards the worker's wake-up messages to requestSync('sw'); returns a stop function. */
export function listenForServiceWorkerMessages(given?: MessageSource): () => void {
    // Missing outside secure contexts and in jsdom, although the DOM types say otherwise.
    const fallback: MessageSource | undefined =
        'serviceWorker' in navigator ? navigator.serviceWorker : undefined;
    const source = given ?? fallback;
    if (source === undefined) {
        return () => undefined;
    }
    const onMessage = (event: Event): void => {
        if (isSyncMessage((event as MessageEvent<unknown>).data)) {
            requestSync('sw');
        }
    };
    source.addEventListener('message', onMessage);
    return () => {
        source.removeEventListener('message', onMessage);
    };
}
