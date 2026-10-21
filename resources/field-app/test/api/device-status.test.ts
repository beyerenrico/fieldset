import { http, HttpResponse } from 'msw';
import { describe, expect, it, vi } from 'vitest';
import { apiRequest } from '../../src/lib/api/client.ts';
import { fetchDeviceStatus } from '../../src/lib/api/device-status.ts';
import { DeviceWipedError, ReauthRequiredError } from '../../src/lib/api/errors.ts';
import { getDeviceRecord, saveDeviceRecord } from '../../src/lib/device/credentials.ts';
import { onLocalDataCleared } from '../../src/lib/session/local-data.ts';
import { getOrganizationId, setOrganizationId } from '../../src/lib/session/org-store.ts';
import { getMeta, setMeta } from '../../src/lib/db/db.ts';
import { hardReload } from '../../src/lib/session/reload.ts';
import { readJson, writeJson } from '../../src/lib/storage/local.ts';
import { fail, ok } from '../utils/backend.ts';
import { stubBrowserCaches } from '../utils/c-rows.ts';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('../../src/lib/session/reload.ts', () => ({
    LOGIN_PATH: '/app/login',
    hardReload: vi.fn(),
}));

setupTestServer();

const DEVICE = { deviceUuid: '0192f1c4-0000-7000-8000-00000000000d', secret: 's3cret' };

function sessionExpiredOnce(path: string, status = 401) {
    let calls = 0;
    return http.get(path, () => {
        calls++;
        return calls === 1 ? fail(status, 'unauthenticated') : ok({ calls });
    });
}

describe('device-status recovery', () => {
    it('ok → refreshes the CSRF cookie and retries the request once', async () => {
        await saveDeviceRecord(DEVICE);
        const seen: string[] = [];
        let deviceHeaders: [string | null, string | null] = [null, null];
        server.use(
            sessionExpiredOnce('*/api/v1/forms', 419),
            http.post('*/api/v1/auth/device-status', ({ request }) => {
                deviceHeaders = [
                    request.headers.get('X-Device-Id'),
                    request.headers.get('X-Device-Secret'),
                ];
                seen.push('status');
                return ok({ status: 'ok' });
            }),
            http.get('*/sanctum/csrf-cookie', () => {
                seen.push('csrf');
                return new HttpResponse(null, { status: 204 });
            }),
        );
        const response = await apiRequest<{ calls: number }>('/api/v1/forms');
        expect(response.data.calls).toBe(2);
        expect(seen).toEqual(['status', 'csrf']);
        expect(deviceHeaders).toEqual([DEVICE.deviceUuid, DEVICE.secret]);
    });

    it('reauth → ReauthRequiredError and keeps local data', async () => {
        await saveDeviceRecord(DEVICE);
        await setMeta('seq', 7); // stands in for unsynced local data
        server.use(
            http.get('*/api/v1/forms', () => fail(401, 'unauthenticated')),
            http.post('*/api/v1/auth/device-status', () => ok({ status: 'reauth' })),
        );
        const error = await apiRequest('/api/v1/forms').catch((e: unknown) => e);
        expect(error).toBeInstanceOf(ReauthRequiredError);
        expect((error as ReauthRequiredError).expired).toBe(true);
        expect(await getMeta('seq')).toBe(7);
    });

    it('wipe → clears IndexedDB, app storage and caches, then throws DeviceWipedError', async () => {
        await saveDeviceRecord(DEVICE);
        await setMeta('seq', 7); // stands in for unsynced local data
        setOrganizationId(1);
        writeJson('favorites.1', [3]);
        writeJson('settings', { largeText: true });
        sessionStorage.setItem('fieldset.idem.x.1', '"k"');
        let hookCalls = 0;
        const stop = onLocalDataCleared(() => {
            hookCalls++;
        });
        server.use(
            http.get('*/api/v1/forms', () => fail(401, 'unauthenticated')),
            http.post('*/api/v1/auth/device-status', () => ok({ status: 'wipe' })),
        );
        const browser = stubBrowserCaches();
        await expect(apiRequest('/api/v1/forms')).rejects.toBeInstanceOf(DeviceWipedError);
        stop();
        browser.restore();
        expect(browser.deleted).toEqual(['workbox-precache', 'runtime']);
        expect(browser.unregister).toHaveBeenCalledTimes(1);
        expect(hardReload).toHaveBeenCalledWith('/app/login?notice=wiped');
        expect(await getMeta('seq')).toBeUndefined();
        expect(await getDeviceRecord()).toBeUndefined();
        expect(getOrganizationId()).toBeNull();
        expect(readJson('favorites.1', null)).toBeNull();
        expect(sessionStorage.getItem('fieldset.idem.x.1')).toBeNull();
        expect(readJson('settings', null)).toEqual({ largeText: true });
        expect(hookCalls).toBe(1);
    });

    it('device_unknown (401) → reauth and drops the stale secret, never loops', async () => {
        await saveDeviceRecord(DEVICE);
        let statusCalls = 0;
        server.use(
            http.get('*/api/v1/forms', () => fail(401, 'unauthenticated')),
            http.post('*/api/v1/auth/device-status', () => {
                statusCalls++;
                return fail(401, 'device_unknown');
            }),
        );
        await expect(apiRequest('/api/v1/forms')).rejects.toBeInstanceOf(ReauthRequiredError);
        expect(statusCalls).toBe(1);
        expect(await getDeviceRecord()).toEqual({ deviceUuid: DEVICE.deviceUuid, secret: null });
    });

    it('without credentials → reauth without calling the server (not expired)', async () => {
        server.use(http.get('*/api/v1/me', () => fail(401, 'unauthenticated')));
        const error = await apiRequest('/api/v1/me').catch((e: unknown) => e);
        expect(error).toBeInstanceOf(ReauthRequiredError);
        expect((error as ReauthRequiredError).expired).toBe(false);
    });

    it('concurrent 401s share one device-status call', async () => {
        await saveDeviceRecord(DEVICE);
        let statusCalls = 0;
        const counts = new Map<string, number>();
        server.use(
            http.get('*/api/v1/r/:n', ({ params }) => {
                const key = String(params.n);
                const count = (counts.get(key) ?? 0) + 1;
                counts.set(key, count);
                return count === 1 ? fail(401, 'unauthenticated') : ok(key);
            }),
            http.post('*/api/v1/auth/device-status', async () => {
                statusCalls++;
                await new Promise((resolve) => setTimeout(resolve, 20));
                return ok({ status: 'ok' });
            }),
        );
        const results = await Promise.all(
            ['1', '2', '3'].map((n) => apiRequest<string>(`/api/v1/r/${n}`)),
        );
        expect(results.map((result) => result.data)).toEqual(['1', '2', '3']);
        expect(statusCalls).toBe(1);
    });

    it('a second 401 after recovery becomes ReauthRequiredError', async () => {
        await saveDeviceRecord(DEVICE);
        server.use(
            http.get('*/api/v1/forms', () => fail(401, 'unauthenticated')),
            http.post('*/api/v1/auth/device-status', () => ok({ status: 'ok' })),
        );
        await expect(apiRequest('/api/v1/forms')).rejects.toBeInstanceOf(ReauthRequiredError);
    });

    it('does not recover when disabled or for other errors', async () => {
        server.use(
            http.get('*/api/v1/x', () => fail(401, 'unauthenticated')),
            http.get('*/api/v1/y', () => fail(403, 'forbidden')),
        );
        await expect(apiRequest('/api/v1/x', { recover: false })).rejects.toMatchObject({
            status: 401,
        });
        await expect(apiRequest('/api/v1/y')).rejects.toMatchObject({ code: 'forbidden' });
    });

    it('maps unexpected device-status answers to reauth and propagates network errors', async () => {
        await saveDeviceRecord(DEVICE);
        server.use(http.post('*/api/v1/auth/device-status', () => ok({ status: 'weird' })));
        expect(await fetchDeviceStatus()).toBe('reauth');
        server.use(http.post('*/api/v1/auth/device-status', () => fail(500, 'boom')));
        expect(await fetchDeviceStatus()).toBe('reauth');
        server.use(http.post('*/api/v1/auth/device-status', () => HttpResponse.error()));
        await expect(fetchDeviceStatus()).rejects.toThrow();
    });
});
