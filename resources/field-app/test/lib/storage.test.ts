import { describe, expect, it, vi } from 'vitest';
import {
    getDeviceRecord,
    saveDeviceRecord,
    dropDeviceSecret,
} from '../../src/lib/device/credentials.ts';
import { db, getMeta, setMeta } from '../../src/lib/db/db.ts';
import { deviceName, ensureDeviceRegistered } from '../../src/lib/device/register.ts';
import {
    getFavorites,
    getRecent,
    purgeShortcuts,
    recordRecent,
    toggleFavorite,
} from '../../src/lib/forms/shortcuts.ts';
import { UUID_V7, newId } from '../../src/lib/ids.ts';
import { clearLocalData } from '../../src/lib/session/local-data.ts';
import { clearAppKeys, readJson, removeKey, writeJson } from '../../src/lib/storage/local.ts';
import { clearIdempotencyKey, getIdempotencyKey } from '../../src/lib/submit/idempotency.ts';
import { createBackend } from '../utils/backend.ts';
import { server, setupTestServer } from '../utils/server.ts';

setupTestServer();

describe('local storage helpers', () => {
    it('reads JSON with fallbacks and clears only app keys', () => {
        writeJson('x', [1]);
        localStorage.setItem('fsa.bad', '{');
        localStorage.setItem('other', 'keep');
        expect(readJson('x', [])).toEqual([1]);
        expect(readJson('bad', 'fallback')).toBe('fallback');
        expect(readJson('missing', 0)).toBe(0);
        removeKey('x');
        expect(readJson('x', null)).toBeNull();
        writeJson('settings', { largeText: true });
        writeJson('y', 1);
        clearAppKeys(['settings']);
        expect(localStorage.getItem('other')).toBe('keep');
        expect(readJson('settings', null)).toEqual({ largeText: true });
        expect(readJson('y', null)).toBeNull();
    });
});

describe('ids and idempotency keys', () => {
    it('creates UUIDv7 ids', () => {
        expect(newId()).toMatch(UUID_V7);
    });

    it('keeps one key per (submission, revision)', () => {
        const first = getIdempotencyKey('u1', 2);
        expect(getIdempotencyKey('u1', 2)).toBe(first);
        expect(getIdempotencyKey('u1', 3)).not.toBe(first);
        expect(first).toMatch(/^[A-Za-z0-9._:-]{8,128}$/);
        clearIdempotencyKey('u1', 2);
        expect(getIdempotencyKey('u1', 2)).not.toBe(first);
    });
});

describe('shortcuts', () => {
    it('toggles favorites, records recent forms and purges revoked ones', () => {
        expect(toggleFavorite(1, 3)).toEqual([3]);
        expect(toggleFavorite(1, 4)).toEqual([3, 4]);
        expect(toggleFavorite(1, 3)).toEqual([4]);
        for (const id of [1, 2, 3, 4, 5, 6, 7, 3]) {
            recordRecent(1, id);
        }
        expect(getRecent(1)).toEqual([3, 7, 6, 5, 4, 2]);
        purgeShortcuts(1, [3, 5]);
        expect(getFavorites(1)).toEqual([]);
        expect(getRecent(1)).toEqual([3, 5]);
        localStorage.setItem('fsa.favorites.2', '["x", 4]');
        expect(getFavorites(2)).toEqual([4]);
    });
});

describe('device registration', () => {
    it('names devices from the user agent', () => {
        expect(
            deviceName('Mozilla/5.0 (Linux; Android 14) Chrome/130.0 Mobile Safari/537.36'),
        ).toBe('Chrome on Android');
        expect(
            deviceName(
                'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Version/18.0 Mobile Safari/604.1',
            ),
        ).toBe('Safari on iOS');
        expect(deviceName('curl/8')).toBe('Browser on unknown system');
    });

    it('registers a new device and stores the secret in IndexedDB', async () => {
        const backend = createBackend({ deviceSecret: 'fresh' });
        server.use(...backend.handlers);
        const spy = vi.spyOn(window, 'fetch');
        const record = await ensureDeviceRegistered();
        const init = spy.mock.calls.at(-1)?.[1];
        const body = JSON.parse(init?.body as string) as Record<string, string>;
        spy.mockRestore();
        expect(record.secret).toBe('fresh');
        expect(body.device_uuid).toMatch(UUID_V7);
        expect(body.platform).toBe('web');
        expect(await getDeviceRecord()).toEqual({ deviceUuid: body.device_uuid, secret: 'fresh' });
    });

    it('re-registers the stored uuid (secret rotation) and survives logout without the secret', async () => {
        server.use(...createBackend({ deviceSecret: 'rotated' }).handlers);
        await saveDeviceRecord({ deviceUuid: 'dev-1', secret: null });
        expect(await ensureDeviceRegistered()).toEqual({ deviceUuid: 'dev-1', secret: 'rotated' });
        await setMeta('seq', 7);
        await clearLocalData({ keepDeviceId: true });
        expect(await getDeviceRecord()).toEqual({ deviceUuid: 'dev-1', secret: null });
        expect(await getMeta('seq')).toBeUndefined();
        await dropDeviceSecret();
        await db.meta.put({ key: 'device', value: 'garbage' });
        expect(await getDeviceRecord()).toBeUndefined();
        await dropDeviceSecret();
    });
});
