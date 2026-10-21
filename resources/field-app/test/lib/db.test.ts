import { describe, expect, it } from 'vitest';
import { DB_NAME, clearAllTables, db, getMeta, nextSeq, setMeta } from '../../src/lib/db/db.ts';
import { setupTestServer } from '../utils/server.ts';

setupTestServer();

describe('offline store', () => {
    it('opens the offline store under the fieldset-offline name', async () => {
        await getMeta('seq');
        expect(DB_NAME).toBe('fieldset-offline');
        expect(db.name).toBe(DB_NAME);
        const databases = await indexedDB.databases();
        expect(databases.map((database) => database.name)).toContain('fieldset-offline');
    });

    it('numbers outbox entries in order and clears every table', async () => {
        const first = await nextSeq();
        expect(await nextSeq()).toBe(first + 1);
        await setMeta('device', { deviceUuid: 'd', secret: null });

        await clearAllTables();

        expect(await db.meta.count()).toBe(0);
        expect(await nextSeq()).toBe(1);
    });
});

describe('current scope', () => {
    it('needs both a user and an organization', async () => {
        const { currentScope, setCurrentUserId } = await import('../../src/lib/db/scope.ts');
        const { setOrganizationId } = await import('../../src/lib/session/org-store.ts');
        setCurrentUserId(null);
        setOrganizationId(3);
        expect(currentScope()).toBeNull();
        setCurrentUserId(9);
        expect(currentScope()).toEqual({ orgId: 3, userId: 9 });
        setOrganizationId(null);
        expect(currentScope()).toBeNull();
        setCurrentUserId(null);
    });
});
