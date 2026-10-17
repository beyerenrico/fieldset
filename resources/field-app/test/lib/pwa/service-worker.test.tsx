import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Dexie from 'dexie';
import type { RegisterSWOptions } from 'vite-plugin-pwa/types';
import { afterEach, describe, expect, it, vi } from 'vitest';
import swSyncSource from '../../../public/sw-sync.js?raw';
import { PwaPrompts } from '../../../src/components/pwa/PwaPrompts.tsx';
import { createOfflineDb } from '../../../src/lib/db/db.ts';
import { watchDatabaseVersion } from '../../../src/lib/pwa/db-version.ts';
import { flushOpenDrafts } from '../../../src/lib/pwa/flush-drafts.ts';
import {
    BACKGROUND_SYNC_TAG,
    listenForServiceWorkerMessages,
} from '../../../src/lib/pwa/sw-messages.ts';
import {
    getUpdateState,
    resetUpdateStateForTests,
    startServiceWorker,
    type RegisterSW,
} from '../../../src/lib/pwa/sw-update.ts';
import { onSyncRequested, resetSyncTriggersForTests } from '../../../src/lib/sync/triggers.ts';
import { MAC, renderWithI18n } from '../../utils/b-pwa.tsx';

vi.mock('../../../src/lib/pwa/flush-drafts.ts', () => ({ flushOpenDrafts: vi.fn() }));

afterEach(() => {
    resetUpdateStateForTests();
    resetSyncTriggersForTests();
    vi.mocked(flushOpenDrafts).mockReset();
});

describe('Background Sync wake-up', () => {
    it('turns the worker message into requestSync("sw") and ignores other messages', () => {
        const reasons: string[] = [];
        onSyncRequested((reason) => reasons.push(reason));
        const worker = new EventTarget();
        const stop = listenForServiceWorkerMessages(worker);
        worker.dispatchEvent(new MessageEvent('message', { data: { type: 'other' } }));
        worker.dispatchEvent(new MessageEvent('message', { data: 'fsa-sync' }));
        worker.dispatchEvent(new MessageEvent('message', { data: { type: 'fsa-sync' } }));
        stop();
        worker.dispatchEvent(new MessageEvent('message', { data: { type: 'fsa-sync' } }));
        expect(reasons).toEqual(['sw']);
    });

    it('public/sw-sync.js posts fsa-sync to every open tab for the fsa-outbox tag only', async () => {
        const handlers = new Map<string, (event: unknown) => void>();
        const clients = [{ postMessage: vi.fn() }, { postMessage: vi.fn() }];
        const matchAll = vi.fn(() => Promise.resolve(clients));
        const worker = {
            addEventListener: (type: string, handler: (event: unknown) => void) => {
                handlers.set(type, handler);
            },
            clients: { matchAll },
        };
        // eslint-disable-next-line @typescript-eslint/no-implied-eval -- runs our own static worker script against a fake worker scope
        const runWorkerScript = new Function('self', swSyncSource) as (scope: unknown) => void;
        runWorkerScript(worker);

        const waits: Promise<unknown>[] = [];
        const sync = (tag: string) => ({
            tag,
            waitUntil: (work: Promise<unknown>) => waits.push(work),
        });
        handlers.get('sync')?.(sync('other-tag'));
        expect(waits).toHaveLength(0);
        handlers.get('sync')?.(sync(BACKGROUND_SYNC_TAG));
        await Promise.all(waits);
        expect(matchAll).toHaveBeenCalledWith({ type: 'window', includeUncontrolled: true });
        for (const client of clients) {
            expect(client.postMessage).toHaveBeenCalledWith({ type: 'fsa-sync' });
        }
    });
});

function fakeRegister() {
    const order: string[] = [];
    let options: RegisterSWOptions = {};
    const activate = vi.fn(() => {
        order.push('activate');
        options.onNeedReload?.();
        return Promise.resolve();
    });
    const register: RegisterSW = (given) => {
        options = given;
        return activate;
    };
    vi.mocked(flushOpenDrafts).mockImplementation(() => {
        order.push('flush');
        return Promise.resolve();
    });
    return { register, activate, order, options: () => options };
}

describe('service worker updates', () => {
    it('shows the update banner, flushes open drafts, then activates and reloads', async () => {
        const user = userEvent.setup();
        const reload = vi.fn();
        const sw = fakeRegister();
        startServiceWorker(sw.register, reload);
        renderWithI18n(<PwaPrompts platform={MAC} />);
        expect(screen.queryByText(/new version/)).not.toBeInTheDocument();

        sw.options().onNeedRefresh?.();
        const banner = await screen.findByRole('status');
        expect(banner).toHaveTextContent('A new version of the app is available.');
        await user.click(screen.getByRole('button', { name: 'Update now' }));

        expect(sw.order).toEqual(['flush', 'activate']);
        expect(reload).toHaveBeenCalledOnce();
        expect(screen.getByRole('button', { name: 'Updating…' })).toHaveAttribute(
            'aria-disabled',
            'true',
        );
        await user.click(screen.getByRole('button', { name: 'Updating…' }));
        expect(sw.activate).toHaveBeenCalledOnce();
    });

    it('activates even when flushing fails, and "Later" hides the banner', async () => {
        const user = userEvent.setup();
        const sw = fakeRegister();
        vi.mocked(flushOpenDrafts).mockRejectedValue(new Error('flush failed'));
        const logged = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        startServiceWorker(sw.register, vi.fn());
        sw.options().onNeedRefresh?.();
        renderWithI18n(<PwaPrompts platform={MAC} />);
        await user.click(screen.getByRole('button', { name: 'Later' }));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();

        sw.options().onNeedRefresh?.();
        await user.click(await screen.findByRole('button', { name: 'Update now' }));
        expect(sw.activate).toHaveBeenCalledOnce();
        expect(logged).toHaveBeenCalled();
    });
});

describe('offline store schema changes', () => {
    it('shows a reload banner when another tab upgrades the store', async () => {
        const user = userEvent.setup();
        const reload = vi.fn();
        const name = `fsa-version-test-${String(Date.now())}`;
        const current = createOfflineDb(name);
        await current.open();
        const stop = watchDatabaseVersion(current);
        renderWithI18n(<PwaPrompts platform={MAC} reload={reload} />);

        const newer = new Dexie(name);
        newer.version(2).stores({ extra: 'id' });
        vi.spyOn(console, 'warn').mockImplementation(() => undefined);
        await newer.open();
        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('The app was updated in another tab.');
        await user.click(screen.getByRole('button', { name: 'Reload' }));
        expect(reload).toHaveBeenCalledOnce();

        stop();
        newer.close();
        await Dexie.delete(name);
    });

    it('asks to close other tabs when its own upgrade is blocked', () => {
        const current = createOfflineDb(`fsa-blocked-test-${String(Date.now())}`);
        watchDatabaseVersion(current);
        vi.spyOn(console, 'warn').mockImplementation(() => undefined);
        current.on('blocked').fire(new Event('blocked'));
        expect(getUpdateState().database).toBe('blocked');
        renderWithI18n(<PwaPrompts platform={MAC} />);
        expect(screen.getByRole('alert')).toHaveTextContent('Close the other tabs of the app');
    });
});
