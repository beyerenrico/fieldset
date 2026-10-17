/** `GET /me` with the cached-session fallback (ADR 0035 "offline start"). */
import { http, HttpResponse } from 'msw';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    DeviceWipedError,
    NetworkError,
    ReauthRequiredError,
} from '../../../src/lib/api/errors.ts';
import { getMeta } from '../../../src/lib/db/db.ts';
import { getCurrentUserId, setCurrentUserId } from '../../../src/lib/db/scope.ts';
import { saveDeviceRecord } from '../../../src/lib/device/credentials.ts';
import {
    ME_TIMEOUT_MS,
    fetchFreshMe,
    fetchSessionMe,
    isCachedSession,
    isUnreachable,
} from '../../../src/lib/session/offline-start.ts';
import { forgetSession, loadSession, saveSession } from '../../../src/lib/session/session-cache.ts';
import { reportAuthError } from '../../../src/lib/session/auth-events.ts';
import { markPendingLogout } from '../../../src/lib/session/pending-logout.ts';
import { db } from '../../../src/lib/db/db.ts';
import { fail, ok } from '../../utils/backend.ts';
import { seedUnsynced } from '../../utils/c-rows.ts';
import { ORG_A, ORG_B, makeMe, makeVersion } from '../../utils/fixtures.ts';
import { server, setupTestServer } from '../../utils/server.ts';

vi.mock('../../../src/lib/session/reload.ts', () => ({
    LOGIN_PATH: '/app/login',
    hardReload: vi.fn(),
}));

setupTestServer();

afterEach(() => {
    vi.useRealTimers();
    setCurrentUserId(null);
});

const cachedMe = makeMe([ORG_A, ORG_B]);

describe('fetchSessionMe', () => {
    it('stores every fresh answer and sets the current user', async () => {
        server.use(http.get('*/api/v1/me', () => ok(makeMe())));
        const me = await fetchSessionMe();
        expect(me.user.id).toBe(7);
        expect(isCachedSession(me)).toBe(false);
        expect(getCurrentUserId()).toBe(7);
        expect(await loadSession()).toMatchObject({ userId: 7, me: makeMe() });
    });

    it('starts from the cached session when the network fails', async () => {
        await saveSession(cachedMe, 123);
        server.use(http.get('*/api/v1/me', () => HttpResponse.error()));
        const me = await fetchSessionMe();
        expect(me).toEqual(cachedMe);
        expect(isCachedSession(me)).toBe(true);
        expect(getCurrentUserId()).toBe(7);
        expect((await getMeta('session'))?.savedAt).toBe(123);
    });

    it('treats gateway errors as unreachable, not other server errors', async () => {
        await saveSession(cachedMe);
        server.use(http.get('*/api/v1/me', () => fail(503, 'down')));
        expect(await fetchSessionMe()).toEqual(cachedMe);
        server.use(http.get('*/api/v1/me', () => fail(500, 'bug')));
        await expect(fetchSessionMe()).rejects.toMatchObject({ status: 500 });
        expect(isUnreachable(new NetworkError('x'))).toBe(true);
    });

    it('fails without a cached session', async () => {
        server.use(http.get('*/api/v1/me', () => HttpResponse.error()));
        await expect(fetchSessionMe()).rejects.toBeInstanceOf(NetworkError);
        expect(getCurrentUserId()).toBeNull();
    });

    it('still runs device-status on 401: reauth and wipe are never masked by the cache', async () => {
        await saveDeviceRecord({ deviceUuid: 'd1', secret: 's1' });
        await saveSession(cachedMe);
        server.use(
            http.get('*/api/v1/me', () => fail(401, 'unauthenticated')),
            http.post('*/api/v1/auth/device-status', () => ok({ status: 'reauth' })),
        );
        await expect(fetchSessionMe()).rejects.toBeInstanceOf(ReauthRequiredError);
        expect(await loadSession()).toBeDefined(); // reauth keeps everything
        await vi.waitFor(async () => {
            expect((await loadSession())?.reauth).toBe(true); // ... but may not open it offline
        });

        server.use(http.post('*/api/v1/auth/device-status', () => ok({ status: 'wipe' })));
        await expect(fetchSessionMe()).rejects.toBeInstanceOf(DeviceWipedError);
        expect(await loadSession()).toBeUndefined();
    });

    it('falls back after a deadline on a hanging connection', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        await saveSession(cachedMe);
        server.use(http.get('*/api/v1/me', () => new Promise<never>(() => undefined)));
        const pending = fetchSessionMe();
        await vi.advanceTimersByTimeAsync(ME_TIMEOUT_MS + 10);
        expect(await pending).toEqual(cachedMe);
    });

    it('never uses the cache for the fresh fetch after a sign-in', async () => {
        await saveSession(cachedMe);
        server.use(http.get('*/api/v1/me', () => HttpResponse.error()));
        await expect(fetchFreshMe()).rejects.toBeInstanceOf(NetworkError);
        await forgetSession();
        expect(await loadSession()).toBeUndefined();
    });

    it('shows the login page instead of a session the server ended, keeping every row', async () => {
        await saveSession(cachedMe);
        await seedUnsynced();
        reportAuthError(new ReauthRequiredError('expired'));
        await vi.waitFor(async () => {
            expect((await loadSession())?.reauth).toBe(true);
        });
        server.use(http.get('*/api/v1/me', () => HttpResponse.error()));
        await expect(fetchSessionMe()).rejects.toMatchObject({
            name: 'ReauthRequiredError',
            expired: true,
        });
        expect(getCurrentUserId()).toBeNull();
        expect(await db.drafts.count()).toBe(1);

        // A fresh /me (sign-in) stores an unmarked session again.
        server.use(http.get('*/api/v1/me', () => ok(cachedMe)));
        await fetchFreshMe();
        expect((await loadSession())?.reauth).toBeUndefined();
        server.use(http.get('*/api/v1/me', () => HttpResponse.error()));
        expect(await fetchSessionMe()).toEqual(cachedMe);
    });

    it('never asks /me while a sign-out waits for the server', async () => {
        await saveSession(cachedMe);
        let calls = 0;
        server.use(
            http.get('*/api/v1/me', () => {
                calls++;
                return ok(cachedMe);
            }),
        );
        markPendingLogout();
        await expect(fetchSessionMe()).rejects.toBeInstanceOf(ReauthRequiredError);
        expect(calls).toBe(0);
    });

    it("purges the user's rows and catalogs of organizations missing from a fresh /me", async () => {
        await seedUnsynced({ orgId: 1, userId: 7 }, 'in-a');
        await seedUnsynced({ orgId: 2, userId: 7 }, 'in-b');
        await seedUnsynced({ orgId: 2, userId: 99 }, 'theirs-in-b');
        await db.formVersions.put({ ...makeVersion(8, 4), orgId: 3, cachedAt: 1 });
        await db.meta.put({ key: 'catalog:2', value: { cursor: 'c', refreshedAt: 1 } });
        await db.meta.put({ key: 'catalog:3', value: { cursor: 'c', refreshedAt: 1 } });
        server.use(http.get('*/api/v1/me', () => ok(makeMe([ORG_A]))));
        await fetchFreshMe();
        expect(await db.drafts.toCollection().primaryKeys()).toEqual(['in-a', 'theirs-in-b']);
        expect(await db.outbox.count()).toBe(2);
        expect(await db.formVersions.count()).toBe(0);
        expect(await db.meta.get('catalog:2')).toBeUndefined();
        expect(await db.meta.get('catalog:3')).toBeUndefined();
    });

    it('ignores an unreadable cached session', async () => {
        const { db } = await import('../../../src/lib/db/db.ts');
        await db.meta.put({ key: 'session', value: { userId: 'x' } });
        expect(await loadSession()).toBeUndefined();
    });
});
