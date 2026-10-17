/** 403 organization_forbidden → /me → purge or wipe (ADR 0035, docs/api/field-app-v1.md). */
import { http } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { db, getMeta, setMeta } from '../../../src/lib/db/db.ts';
import { getCurrentUserId } from '../../../src/lib/db/scope.ts';
import { getDeviceRecord, saveDeviceRecord } from '../../../src/lib/device/credentials.ts';
import { onAuthLost } from '../../../src/lib/session/auth-events.ts';
import { onSessionRefreshed } from '../../../src/lib/session/offline-start.ts';
import { handleOrganizationForbidden } from '../../../src/lib/session/org-access.ts';
import { setOrganizationId } from '../../../src/lib/session/org-store.ts';
import { hardReload } from '../../../src/lib/session/reload.ts';
import { fail, ok } from '../../utils/backend.ts';
import { seedUnsynced } from '../../utils/c-rows.ts';
import { ORG_A, ORG_B, makeMe } from '../../utils/fixtures.ts';
import { server, setupTestServer } from '../../utils/server.ts';

vi.mock('../../../src/lib/session/reload.ts', () => ({
    LOGIN_PATH: '/app/login',
    hardReload: vi.fn(),
}));

setupTestServer();

beforeEach(async () => {
    vi.mocked(hardReload).mockClear();
    await saveDeviceRecord({ deviceUuid: 'dev-1', secret: 'sec-1' });
    await seedUnsynced({ orgId: 1, userId: 7 }, 'in-a');
    await seedUnsynced({ orgId: 2, userId: 7 }, 'in-b');
    await seedUnsynced({ orgId: 1, userId: 99 }, 'other-user');
    await db.forms.put({
        orgId: 1,
        id: 3,
        key: 'k',
        name: 'Form',
        category: null,
        version: { id: 7, version: 1, checksum: 'c' },
        cachedAt: 1,
    });
    await setMeta('catalog:1', { cursor: 'c1', refreshedAt: 1 });
});

afterEach(() => {
    vi.restoreAllMocks();
});

const meWith = (organizations: Parameters<typeof makeMe>[0]) =>
    http.get('*/api/v1/me', () => ok(makeMe(organizations)));

describe('organization_forbidden', () => {
    it('keeps everything while the user is still a member', async () => {
        server.use(meWith([ORG_A, ORG_B]));
        expect(await handleOrganizationForbidden(1)).toBe('member');
        expect(await db.drafts.count()).toBe(3);
        expect(getCurrentUserId()).toBe(7);
        expect((await getMeta('session'))?.userId).toBe(7);
    });

    it("purges the user's rows of the lost organization and announces the new session", async () => {
        setOrganizationId(1);
        const refreshed: number[] = [];
        const stop = onSessionRefreshed((me) => refreshed.push(me.organizations.length));
        server.use(meWith([ORG_B]));
        expect(await handleOrganizationForbidden(1)).toBe('purged');
        stop();
        expect(await db.drafts.toCollection().primaryKeys()).toEqual(['in-b', 'other-user']);
        expect(await db.outbox.count()).toBe(2);
        expect(await db.attachments.count()).toBe(2);
        expect(await db.forms.count()).toBe(0);
        expect(await getMeta('catalog:1')).toBeUndefined();
        expect(refreshed).toEqual([1]);
    });

    it('wipes the device when no organization is left and device-status says wipe', async () => {
        vi.spyOn(console, 'warn').mockImplementation(() => undefined);
        const notices: unknown[] = [];
        const stop = onAuthLost((notice) => notices.push(notice));
        server.use(
            meWith([]),
            http.post('*/api/v1/auth/device-status', () => ok({ status: 'wipe' })),
        );
        expect(await handleOrganizationForbidden(1)).toBe('wiped');
        stop();
        expect(await db.drafts.count()).toBe(0);
        expect(await getDeviceRecord()).toBeUndefined();
        expect(hardReload).toHaveBeenCalledWith('/app/login?notice=wiped');
        expect(notices).toEqual(['wiped']);
    });

    it('only purges when device-status does not ask for a wipe; shares concurrent checks', async () => {
        let meCalls = 0;
        server.use(
            http.get('*/api/v1/me', () => {
                meCalls++;
                return ok(makeMe([]));
            }),
            http.post('*/api/v1/auth/device-status', () => ok({ status: 'ok' })),
        );
        const results = await Promise.all([
            handleOrganizationForbidden(1),
            handleOrganizationForbidden(1),
        ]);
        expect(results).toEqual(['purged', 'purged']);
        expect(meCalls).toBe(1);
        expect(await getDeviceRecord()).toEqual({ deviceUuid: 'dev-1', secret: 'sec-1' });
        // No organization left: the user's rows of org 2 go too (purge on every fresh /me).
        expect(await db.drafts.toCollection().primaryKeys()).toEqual(['other-user']);
    });

    it('propagates a lost session instead of guessing', async () => {
        server.use(
            http.get('*/api/v1/me', () => fail(401, 'unauthenticated')),
            http.post('*/api/v1/auth/device-status', () => ok({ status: 'reauth' })),
        );
        await expect(handleOrganizationForbidden(1)).rejects.toMatchObject({
            name: 'ReauthRequiredError',
        });
        expect(await db.drafts.count()).toBe(3);
    });
});
