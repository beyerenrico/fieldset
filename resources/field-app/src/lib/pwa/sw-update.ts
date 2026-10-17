/**
 * Service worker updates (registerType 'prompt', ADR 0035). A waiting worker shows the update
 * banner; accepting it writes the pending edits of open drafts on this device, activates the worker
 * and reloads once it controls the page. A Dexie `versionchange` (another tab upgraded the store) or `blocked` (an old tab holds the
 * store) shows a reload banner instead.
 */
import type { RegisterSWOptions } from 'vite-plugin-pwa/types';
import { flushDraftSessions } from '../drafts/draft-session.ts';
import { createStore } from './store.ts';

/** `registerSW` of `virtual:pwa-register` (injected so tests can fake it). */
export type RegisterSW = (options: RegisterSWOptions) => (reloadPage?: boolean) => Promise<void>;

export type DatabaseNotice = 'outdated' | 'blocked';

export interface UpdateState {
    readonly needRefresh: boolean;
    readonly updating: boolean;
    readonly database: DatabaseNotice | null;
}

const INITIAL: UpdateState = { needRefresh: false, updating: false, database: null };
const store = createStore<UpdateState>(INITIAL);
let activate: ((reloadPage?: boolean) => Promise<void>) | null = null;

export const subscribeUpdate = store.subscribe;
export const getUpdateState = store.get;

export function startServiceWorker(
    register: RegisterSW,
    reload: () => void = () => {
        window.location.reload();
    },
): void {
    activate = register({
        onNeedRefresh: () => {
            store.update((state) => ({ ...state, needRefresh: true }));
        },
        // Called in every controlled tab once the new worker takes over: tabs that did not apply
        // the update write their open drafts first (this tab flushed in applyUpdate).
        onNeedReload: () => void reloadAfterFlush(reload),
        onRegisterError: (error: unknown) => {
            console.error('Service worker registration failed', error);
        },
    });
}

/** A failed flush does not block: drafts already live in IndexedDB up to the last autosave. */
async function flushQuietly(): Promise<void> {
    try {
        await flushDraftSessions();
    } catch (error) {
        console.error('Flushing drafts before the update failed', error);
    }
}

async function reloadAfterFlush(reload: () => void): Promise<void> {
    if (!store.get().updating) {
        await flushQuietly();
    }
    reload();
}

/** Flushes open drafts (a failed flush does not block: drafts already live in IndexedDB), then activates. */
export async function applyUpdate(): Promise<void> {
    const state = store.get();
    if (activate === null || state.updating || !state.needRefresh) {
        return;
    }
    store.set({ ...state, updating: true });
    await flushQuietly();
    await activate();
}

export function dismissUpdate(): void {
    store.update((state) => ({ ...state, needRefresh: false }));
}

export function showDatabaseNotice(notice: DatabaseNotice): void {
    store.update((state) => ({ ...state, database: state.database ?? notice }));
}

/** Tests only. */
export function resetUpdateStateForTests(): void {
    activate = null;
    store.set(INITIAL);
}
