import { describe, expect, it } from 'vitest';
import { refreshCatalog } from '../../../src/lib/catalog/catalog.ts';
import { readCatalogMeta } from '../../../src/lib/catalog/refresh.ts';
import { db } from '../../../src/lib/db/db.ts';
import type { DraftRow } from '../../../src/lib/db/schema.ts';
import {
    getFavorites,
    getRecent,
    recordRecent,
    toggleFavorite,
} from '../../../src/lib/forms/shortcuts.ts';
import { createCatalogBackend, summary } from '../../utils/b-catalog.ts';
import { server, setupTestServer } from '../../utils/server.ts';

setupTestServer();

const ORG = 1;

function draft(uuid: string, formVersionId: number, orgId = ORG): DraftRow {
    return {
        orgId,
        userId: 7,
        uuid,
        formId: 3,
        formVersionId,
        data: {},
        localVersion: 1,
        syncedVersion: 0,
        serverRevision: 0,
        server: null,
        conflict: null,
        sync: 'local',
        blockedBy: null,
        submitErrors: [],
        createdAt: 1,
        updatedAt: 1,
    };
}

const numeric = (ids: readonly number[]): number[] => [...ids].sort((a, b) => a - b);

async function cachedFormIds(orgId = ORG): Promise<number[]> {
    return numeric((await db.forms.where('orgId').equals(orgId).toArray()).map((row) => row.id));
}

async function cachedVersionIds(orgId = ORG): Promise<number[]> {
    return numeric(await db.formVersions.where('orgId').equals(orgId).primaryKeys());
}

describe('catalog refresh', () => {
    it('loads everything on the first run, then asks the manifest since the stored cursor', async () => {
        const backend = createCatalogBackend({
            manifestForms: [summary(3, 7), summary(4, 8)],
            fillable: [3, 4],
            cursor: 'cursor-1',
        });
        server.use(...backend.handlers);

        const first = await refreshCatalog(ORG);
        expect(first).toEqual({
            cursor: 'cursor-1',
            upserted: 2,
            fetchedVersions: 2,
            purgedFormIds: [],
        });
        expect(await cachedFormIds()).toEqual([3, 4]);
        expect(await cachedVersionIds()).toEqual([7, 8]);
        expect((await readCatalogMeta(ORG))?.cursor).toBe('cursor-1');

        backend.state.manifestForms = [];
        backend.state.cursor = 'cursor-2';
        const second = await refreshCatalog(ORG);
        expect(backend.state.calls.manifest).toEqual([null, 'cursor-1']);
        expect(second.fetchedVersions).toBe(0);
        expect(numeric(backend.state.calls.versions)).toEqual([7, 8]);
        expect(backend.state.calls.forms).toBe(0);
        expect((await readCatalogMeta(ORG))?.cursor).toBe('cursor-2');
        expect(backend.state.calls.orgHeaders.every((header) => header === '1')).toBe(true);
    });

    it('fetches fillable forms that are unknown locally through one GET /forms', async () => {
        const backend = createCatalogBackend({
            forms: [summary(3, 7), summary(5, 9), summary(6, 10)],
            manifestForms: [],
            fillable: [5, 6],
        });
        server.use(...backend.handlers);

        const result = await refreshCatalog(ORG);
        expect(backend.state.calls.forms).toBe(1);
        expect(result.upserted).toBe(2);
        expect(await cachedFormIds()).toEqual([5, 6]);
        expect(await cachedVersionIds()).toEqual([9, 10]);
    });

    it('refetches a version whose checksum changed and upserts the summary by id', async () => {
        const backend = createCatalogBackend({ manifestForms: [summary(3, 7)], fillable: [3] });
        server.use(...backend.handlers);
        await refreshCatalog(ORG);

        // Inclusive cursor: the same form arrives again, now with a changed checksum.
        backend.state.manifestForms = [{ ...summary(3, 7, 'changed'), name: 'Renamed' }];
        backend.state.checksums.set(7, 'changed');
        const result = await refreshCatalog(ORG);

        expect(result.fetchedVersions).toBe(1);
        expect(backend.state.calls.versions).toEqual([7, 7]);
        expect((await db.formVersions.get(7))?.checksum).toBe('changed');
        const rows = await db.forms.where('orgId').equals(ORG).toArray();
        expect(rows.map((row) => row.name)).toEqual(['Renamed']);
    });

    it('purges forms that are no longer fillable but keeps versions used by local drafts', async () => {
        const backend = createCatalogBackend({
            manifestForms: [summary(3, 7), summary(4, 8), summary(5, 9)],
            fillable: [3, 4, 5],
        });
        server.use(...backend.handlers);
        await refreshCatalog(ORG);
        await db.formVersions.put({ ...(await db.formVersions.get(8))!, id: 80 }); // old version of 4
        await db.drafts.bulkPut([draft('d-1', 8), draft('d-other-org', 9, 2)]);

        backend.state.manifestForms = [];
        backend.state.fillable = [3];
        const result = await refreshCatalog(ORG);

        expect(numeric(result.purgedFormIds)).toEqual([4, 5]);
        expect(await cachedFormIds()).toEqual([3]);
        // 8 stays for the local draft; 9 (a draft of another organization) and 80 go.
        expect(await cachedVersionIds()).toEqual([7, 8]);

        await db.drafts.delete('d-1');
        await refreshCatalog(ORG);
        expect(await cachedVersionIds()).toEqual([7]);
    });

    it('marks cached versions listed in retired_version_ids as retired', async () => {
        const backend = createCatalogBackend({ manifestForms: [summary(3, 7)], fillable: [3] });
        server.use(...backend.handlers);
        await refreshCatalog(ORG);
        await db.formVersions.put({ ...(await db.formVersions.get(7))!, id: 6 }); // older version
        await db.drafts.put(draft('pinned-to-6', 6));
        expect((await db.formVersions.get(6))?.retired).toBe(false);

        backend.state.manifestForms = [];
        backend.state.retired = [6, 99]; // 99 was never cached
        await refreshCatalog(ORG);

        expect((await db.formVersions.get(6))?.retired).toBe(true);
        expect((await db.formVersions.get(7))?.retired).toBe(false);
        expect(await db.formVersions.get(99)).toBeUndefined();
        expect(await cachedVersionIds()).toEqual([6, 7]); // kept for the local draft
    });

    it('purges favorites and recent forms as soon as the manifest arrives', async () => {
        toggleFavorite(ORG, 3);
        toggleFavorite(ORG, 99);
        recordRecent(ORG, 98);
        const backend = createCatalogBackend({ manifestForms: [summary(3, 7)], fillable: [3] });
        backend.state.failures.set(7, 500);
        server.use(...backend.handlers);

        await expect(refreshCatalog(ORG)).rejects.toMatchObject({ status: 500 });
        expect(getFavorites(ORG)).toEqual([3]);
        expect(getRecent(ORG)).toEqual([]);
    });

    it('does not advance the cursor or write anything when a download fails', async () => {
        const backend = createCatalogBackend({
            manifestForms: [summary(3, 7)],
            fillable: [3],
            cursor: 'cursor-1',
        });
        server.use(...backend.handlers);
        await refreshCatalog(ORG);

        backend.state.manifestForms = [summary(4, 8), summary(5, 9)];
        backend.state.fillable = [4, 5];
        backend.state.cursor = 'cursor-2';
        backend.state.failures.set(9, 503);
        await expect(refreshCatalog(ORG)).rejects.toMatchObject({ status: 503 });

        expect((await readCatalogMeta(ORG))?.cursor).toBe('cursor-1');
        expect(await cachedFormIds()).toEqual([3]);
        expect(await cachedVersionIds()).toEqual([7]);

        backend.state.failures.clear();
        await refreshCatalog(ORG);
        expect(backend.state.calls.manifest).toEqual([null, 'cursor-1', 'cursor-1']);
        expect(await cachedFormIds()).toEqual([4, 5]);
    });

    it('skips a form whose version answers 404 and fetches it again on the next run', async () => {
        const backend = createCatalogBackend({
            manifestForms: [summary(3, 7), summary(4, 8)],
            fillable: [3, 4],
        });
        backend.state.failures.set(8, 404);
        server.use(...backend.handlers);

        const result = await refreshCatalog(ORG);
        expect(result.upserted).toBe(1);
        expect(await cachedFormIds()).toEqual([3]);

        backend.state.failures.clear();
        backend.state.manifestForms = [];
        backend.state.forms = [summary(3, 7), summary(4, 8)];
        await refreshCatalog(ORG);
        expect(backend.state.calls.forms).toBe(1);
        expect(await cachedFormIds()).toEqual([3, 4]);
    });
});
