/** Logout, wipe and the cross-tab announcement (ADR 0035 "Logout, wipe, reauth"). */
import { http, HttpResponse } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { db, getMeta, setMeta } from '../../../src/lib/db/db.ts';
import { getCurrentUserId, setCurrentUserId } from '../../../src/lib/db/scope.ts';
import { getDeviceRecord, saveDeviceRecord } from '../../../src/lib/device/credentials.ts';
import {
    ACCOUNT_CHANNEL,
    announceAccountEvent,
    onAccountEvent,
    wipeDevice,
} from '../../../src/lib/session/account.ts';
import {
    OTHER_TABS_GRACE_MS,
    onLocalDataCleared,
    onLocalDataClearing,
    onSessionEnding,
    purgeDevice,
} from '../../../src/lib/session/local-data.ts';
import { getOrganizationId, setOrganizationId } from '../../../src/lib/session/org-store.ts';
import { hardReload } from '../../../src/lib/session/reload.ts';
import { signOut } from '../../../src/lib/session/sign-out.ts';
import { readJson, writeJson } from '../../../src/lib/storage/local.ts';
import { seedUnsynced, setOnline, stubBrowserCaches } from '../../utils/c-rows.ts';
import { server, setupTestServer } from '../../utils/server.ts';

vi.mock('../../../src/lib/session/reload.ts', () => ({
    LOGIN_PATH: '/app/login',
    hardReload: vi.fn(),
}));

setupTestServer();

let browser: ReturnType<typeof stubBrowserCaches>;

beforeEach(async () => {
    vi.mocked(hardReload).mockClear();
    browser = stubBrowserCaches();
    await saveDeviceRecord({ deviceUuid: 'dev-1', secret: 'sec-1' });
    await seedUnsynced();
    await setMeta('session', {
        userId: 7,
        me: { user: {}, organizations: [] } as never,
        savedAt: 1,
    });
    setOrganizationId(1);
    setCurrentUserId(7);
    writeJson('favorites.1', [3]);
    writeJson('settings', { largeText: true });
    sessionStorage.setItem('fieldset.idem.x', '"k"');
});

afterEach(() => {
    browser.restore();
    vi.restoreAllMocks();
});

async function expectEverythingGone(): Promise<void> {
    expect(await db.drafts.count()).toBe(0);
    expect(await db.outbox.count()).toBe(0);
    expect(await db.attachments.count()).toBe(0);
    expect(await getMeta('session')).toBeUndefined();
    expect(getCurrentUserId()).toBeNull();
    expect(getOrganizationId()).toBeNull();
    expect(readJson('favorites.1', null)).toBeNull();
    expect(sessionStorage.getItem('fieldset.idem.x')).toBeNull();
    expect(readJson('settings', null)).toEqual({ largeText: true });
    expect(browser.deleted).toEqual(['workbox-precache', 'runtime']);
    expect(browser.unregister).toHaveBeenCalledTimes(1);
}

describe('logout', () => {
    it('logs out, keeps only the device uuid and reloads to the login page', async () => {
        let loggedOut = false;
        server.use(
            http.post('*/auth/logout', () => {
                loggedOut = true;
                return new HttpResponse(null, { status: 204 });
            }),
        );
        expect(await signOut()).toEqual({ reloading: true });
        expect(loggedOut).toBe(true);
        await expectEverythingGone();
        expect(await getDeviceRecord()).toEqual({ deviceUuid: 'dev-1', secret: null });
        expect(hardReload).toHaveBeenCalledWith('/app/login?notice=logout');
    });

    it('clears offline too, but does not reload into the browser offline page', async () => {
        setOnline(false);
        server.use(http.post('*/auth/logout', () => HttpResponse.error()));
        expect(await signOut()).toEqual({ reloading: false });
        await expectEverythingGone();
        expect(hardReload).not.toHaveBeenCalled();
    });
});

describe('wipe', () => {
    it('removes everything including the device uuid and reloads with the wiped notice', async () => {
        await wipeDevice();
        await expectEverythingGone();
        expect(await getDeviceRecord()).toBeUndefined();
        expect(hardReload).toHaveBeenCalledWith('/app/login?notice=wiped');
    });

    it('ends open drafts before the tables are emptied', async () => {
        const order: string[] = [];
        const stopBefore = onLocalDataClearing(async () => {
            order.push(`before:${String(await db.drafts.count())}`);
        });
        const stopAfter = onLocalDataCleared(async () => {
            order.push(`after:${String(await db.drafts.count())}`);
        });
        await purgeDevice({ keepDeviceId: false });
        stopBefore();
        stopAfter();
        expect(order).toEqual(['before:1', 'after:0']);
    });

    it('survives browsers whose cache or service worker APIs fail', async () => {
        vi.spyOn(console, 'warn').mockImplementation(() => undefined);
        vi.stubGlobal('caches', { keys: () => Promise.reject(new Error('blocked')) });
        Object.defineProperty(navigator, 'serviceWorker', {
            configurable: true,
            value: { getRegistrations: () => Promise.reject(new Error('blocked')) },
        });
        await purgeDevice({ keepDeviceId: true });
        expect(await db.drafts.count()).toBe(0);
        expect(await getDeviceRecord()).toEqual({ deviceUuid: 'dev-1', secret: null });
    });
});

describe('other tabs', () => {
    it.each([
        ['logout', () => signOut()],
        ['wiped', () => wipeDevice()],
    ] as const)(
        'hear %s before anything is removed; this tab stops writing and syncing first',
        async (event, end) => {
            const seen: string[] = [];
            const other = new BroadcastChannel(ACCOUNT_CHANNEL);
            other.onmessage = (message: MessageEvent<{ event: string }>) => {
                void db.drafts.count().then((drafts) => {
                    seen.push(`${message.data.event}:drafts=${String(drafts)}`);
                });
            };
            const stopEnding = onSessionEnding(() => {
                seen.push(`ending:user=${String(getCurrentUserId())}`);
            });
            server.use(http.post('*/auth/logout', () => new HttpResponse(null, { status: 204 })));
            const started = Date.now();
            await end();
            stopEnding();
            other.close();
            expect(seen).toEqual(['ending:user=null', `${event}:drafts=1`]);
            expect(Date.now() - started).toBeGreaterThanOrEqual(OTHER_TABS_GRACE_MS - 5);
            expect(await db.drafts.count()).toBe(0);
        },
    );

    it('hears logout and wipe of another tab, not its own announcements', async () => {
        const seen: string[] = [];
        const stop = onAccountEvent((event) => seen.push(event));
        announceAccountEvent('logout'); // this tab: ignored
        const other = new BroadcastChannel(ACCOUNT_CHANNEL);
        other.postMessage({ event: 'wiped', tab: 'another-tab' });
        other.postMessage({ event: 'nonsense', tab: 'another-tab' });
        other.postMessage('wiped');
        await vi.waitFor(() => {
            expect(seen).toEqual(['wiped']);
        });
        other.close();
        stop();
    });
});
