import { http, HttpResponse } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    CATALOG_LOCK,
    getFormVersion,
    loadForms,
    loadFormVersion,
    NotCachedError,
    onCatalogChanged,
    refreshCatalogIfStale,
    runCatalogRefresh,
    startCatalogSync,
} from '../../../src/lib/catalog/catalog.ts';
import { readCatalogMeta } from '../../../src/lib/catalog/refresh.ts';
import { db } from '../../../src/lib/db/db.ts';
import { setCurrentUserId } from '../../../src/lib/db/scope.ts';
import { getFavorites, toggleFavorite } from '../../../src/lib/forms/shortcuts.ts';
import { setOrganizationId } from '../../../src/lib/session/org-store.ts';
import { requestSync, resetSyncTriggersForTests } from '../../../src/lib/sync/triggers.ts';
import { fail } from '../../utils/backend.ts';
import { createCatalogBackend, summary, version } from '../../utils/b-catalog.ts';
import { server, setupTestServer } from '../../utils/server.ts';

setupTestServer();

const ORG = 1;

function goOffline(): void {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
}

beforeEach(() => {
    setOrganizationId(ORG);
    setCurrentUserId(7);
});
afterEach(() => {
    vi.restoreAllMocks();
    setCurrentUserId(null);
    resetSyncTriggersForTests();
});

describe('form versions: cache first', () => {
    it('stores a version fetched from the network and serves it from the cache afterwards', async () => {
        const backend = createCatalogBackend();
        server.use(...backend.handlers);
        const fetched = await getFormVersion(ORG, 3, 7);
        expect(fetched).toEqual(version(7, 3));
        expect(await db.formVersions.get(7)).toMatchObject({ id: 7, orgId: ORG });

        goOffline();
        expect(await loadFormVersion(3, 7)).toEqual(version(7, 3));
        expect(backend.state.calls.versions).toEqual([7]);
    });

    it('raises NotCachedError offline (or on a network error) when the version is missing', async () => {
        server.use(http.get('*/api/v1/forms/:form/versions/:version', () => HttpResponse.error()));
        await expect(getFormVersion(ORG, 3, 7)).rejects.toBeInstanceOf(NotCachedError);
        goOffline();
        await expect(getFormVersion(ORG, 3, 7)).rejects.toBeInstanceOf(NotCachedError);
    });

    it('never serves a version cached for another organization and passes API errors through', async () => {
        await db.formVersions.put({ ...version(7, 3), orgId: 2, cachedAt: 1 });
        server.use(
            http.get('*/api/v1/forms/:form/versions/:version', () => fail(403, 'forbidden')),
        );
        await expect(getFormVersion(ORG, 3, 7)).rejects.toMatchObject({ status: 403 });
    });
});

describe('forms list', () => {
    it('uses GET /forms (stored) until a refresh completed, then the cache sorted by name', async () => {
        const backend = createCatalogBackend({
            forms: [summary(4, 8), { ...summary(3, 7), name: 'Alpha' }],
            manifestForms: [summary(5, 9)],
            fillable: [5],
        });
        server.use(...backend.handlers);
        expect((await loadForms(ORG)).map((form) => form.name)).toEqual(['Alpha', 'Form 4']);
        await vi.waitFor(async () => {
            expect(await db.forms.count()).toBe(2); // cached in the background
        });

        await runCatalogRefresh(ORG);
        expect((await loadForms(ORG)).map((form) => form.id)).toEqual([5]);
        expect(backend.state.calls.forms).toBe(1);
    });

    it('drops revoked favorites as soon as GET /forms answers', async () => {
        toggleFavorite(ORG, 3);
        toggleFavorite(ORG, 99);
        const backend = createCatalogBackend({ forms: [summary(3, 7)] });
        server.use(...backend.handlers);
        const changes: string[] = [];
        const stop = onCatalogChanged((_org, change) => changes.push(change));
        await loadForms(ORG);
        stop();
        expect(getFavorites(ORG)).toEqual([3]);
        expect(changes).toEqual(['shortcuts']);
    });

    it('falls back to cached rows offline and raises NotCachedError with nothing cached', async () => {
        goOffline();
        await expect(loadForms(ORG)).rejects.toBeInstanceOf(NotCachedError);
        await db.forms.put({ ...summary(3, 7), orgId: ORG, cachedAt: 1 });
        expect((await loadForms(ORG)).map((form) => form.id)).toEqual([3]);
    });

    it('keeps API errors (e.g. 403) as they are', async () => {
        server.use(http.get('*/api/v1/forms', () => fail(403, 'forbidden')));
        await expect(loadForms(ORG)).rejects.toMatchObject({ status: 403 });
    });
});

describe('catalog of another user', () => {
    it('stores the user with the cursor and purges a catalog cached for someone else', async () => {
        const backend = createCatalogBackend({ manifestForms: [summary(3, 7)], fillable: [3] });
        server.use(...backend.handlers);
        await runCatalogRefresh(ORG);
        expect(await readCatalogMeta(ORG)).toMatchObject({ cursor: 'c1', userId: 7 });
        expect((await loadForms(ORG)).map((form) => form.id)).toEqual([3]);

        setCurrentUserId(8); // another user signed in on this device (reauth with a new account)
        goOffline();
        await expect(loadForms(ORG)).rejects.toBeInstanceOf(NotCachedError);
        expect(await db.forms.count()).toBe(0);
        expect(await db.formVersions.count()).toBe(0);
        expect(await readCatalogMeta(ORG)).toBeUndefined();
    });

    it("refreshes another user's catalog from scratch, not from the old cursor", async () => {
        const backend = createCatalogBackend({ manifestForms: [summary(3, 7)], fillable: [3] });
        server.use(...backend.handlers);
        await runCatalogRefresh(ORG);
        setCurrentUserId(8);
        backend.state.cursor = 'c2';
        await runCatalogRefresh(ORG);
        expect(backend.state.calls.manifest).toEqual([null, null]);
        expect(await readCatalogMeta(ORG)).toMatchObject({ cursor: 'c2', userId: 8 });
    });

    it('drops cached forms that GET /forms no longer lists', async () => {
        await db.forms.bulkPut([
            { ...summary(3, 7), orgId: ORG, cachedAt: 1 },
            { ...summary(9, 90), orgId: ORG, cachedAt: 1 },
        ]);
        server.use(...createCatalogBackend({ forms: [summary(3, 7)] }).handlers);
        expect((await loadForms(ORG)).map((form) => form.id)).toEqual([3]);
        await vi.waitFor(async () => {
            expect(await db.forms.toCollection().primaryKeys()).toEqual([[ORG, 3]]);
        });
    });
});

describe('refresh scheduling', () => {
    it('runs one refresh per organization at a time in this tab and notifies listeners', async () => {
        const backend = createCatalogBackend({ manifestForms: [summary(3, 7)], fillable: [3] });
        server.use(...backend.handlers);
        const changes: string[] = [];
        const stop = onCatalogChanged((org, change) => changes.push(`${String(org)}:${change}`));
        const [a, b] = await Promise.all([runCatalogRefresh(ORG), runCatalogRefresh(ORG)]);
        stop();
        expect(a).toBe(b);
        expect(backend.state.calls.manifest).toHaveLength(1);
        expect(changes).toEqual(['1:shortcuts', '1:forms']);
    });

    it('skips fresh catalogs and offline devices', async () => {
        const backend = createCatalogBackend({ manifestForms: [summary(3, 7)], fillable: [3] });
        server.use(...backend.handlers);
        expect(await refreshCatalogIfStale(ORG)).not.toBeNull();
        expect(await refreshCatalogIfStale(ORG)).toBeNull();
        expect(await refreshCatalogIfStale(ORG, 0)).not.toBeNull();
        goOffline();
        expect(await refreshCatalogIfStale(ORG, 0)).toBeNull();
        expect(backend.state.calls.manifest).toHaveLength(2);
    });

    it('takes the cross-tab Web Lock and skips when another tab holds it', async () => {
        const backend = createCatalogBackend({ manifestForms: [summary(3, 7)], fillable: [3] });
        server.use(...backend.handlers);
        let held = false;
        const request = vi.fn(
            (_name: string, _options: LockOptions, callback: (lock: Lock | null) => unknown) =>
                Promise.resolve(callback(held ? null : ({ name: CATALOG_LOCK } as Lock))),
        );
        Object.defineProperty(navigator, 'locks', { value: { request }, configurable: true });
        try {
            expect(await runCatalogRefresh(ORG)).not.toBeNull();
            held = true;
            expect(await runCatalogRefresh(ORG)).toBeNull();
            expect(request).toHaveBeenCalledWith(
                CATALOG_LOCK,
                { ifAvailable: true },
                expect.any(Function),
            );
            expect(backend.state.calls.manifest).toHaveLength(1);
        } finally {
            Reflect.deleteProperty(navigator, 'locks');
        }
    });

    it('refreshes on sync requests of a signed-in user, but not on enqueue or interval', async () => {
        const backend = createCatalogBackend({ manifestForms: [summary(3, 7)], fillable: [3] });
        server.use(...backend.handlers);
        const stop = startCatalogSync();
        requestSync('enqueue');
        requestSync('interval');
        await new Promise((resolve) => setTimeout(resolve, 20));
        expect(backend.state.calls.manifest).toHaveLength(0);

        requestSync('online');
        await vi.waitFor(async () => {
            expect(await readCatalogMeta(ORG)).toBeDefined();
        });
        requestSync('visible'); // fresh: skipped
        requestSync('manual'); // forced
        await vi.waitFor(() => {
            expect(backend.state.calls.manifest).toHaveLength(2);
        });

        setCurrentUserId(null);
        requestSync('manual');
        await new Promise((resolve) => setTimeout(resolve, 20));
        expect(backend.state.calls.manifest).toHaveLength(2);
        stop();
    });
});
