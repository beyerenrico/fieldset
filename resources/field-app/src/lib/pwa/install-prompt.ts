/**
 * Chromium's deferred install prompt. `beforeinstallprompt` fires once the install criteria are met
 * (manifest, service worker); it is kept so the Install button can show the prompt later.
 */
import { createStore } from './store.ts';

/** Not in the TypeScript DOM library (Chromium only). */
export interface BeforeInstallPromptEvent extends Event {
    prompt(): Promise<void>;
    readonly userChoice: Promise<{ readonly outcome: 'accepted' | 'dismissed' }>;
}

const store = createStore<BeforeInstallPromptEvent | null>(null);

export const subscribeInstallPrompt = store.subscribe;
export const getInstallPrompt = store.get;

/** Listens on `target` (the window) from app start; returns a stop function. */
export function startInstallCapture(target: EventTarget = window): () => void {
    const onPrompt = (event: Event): void => {
        event.preventDefault(); // No mini-infobar: the app shows its own button.
        store.set(event as BeforeInstallPromptEvent);
    };
    const onInstalled = (): void => {
        store.set(null);
    };
    target.addEventListener('beforeinstallprompt', onPrompt);
    target.addEventListener('appinstalled', onInstalled);
    return () => {
        target.removeEventListener('beforeinstallprompt', onPrompt);
        target.removeEventListener('appinstalled', onInstalled);
    };
}

/** Shows the browser's install dialog; a prompt can be used once, so it is dropped afterwards. */
export async function promptInstall(): Promise<'accepted' | 'dismissed' | 'unavailable'> {
    const event = store.get();
    if (event === null) {
        return 'unavailable';
    }
    store.set(null);
    await event.prompt();
    return (await event.userChoice).outcome;
}

/** Tests only. */
export function resetInstallPromptForTests(): void {
    store.set(null);
}
