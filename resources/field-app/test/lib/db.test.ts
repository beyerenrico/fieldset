import { describe, expect, it } from 'vitest';
import {
    LEGACY_DB_NAME,
    clearAllTables,
    db,
    getMeta,
    nextSeq,
    resetMigrationForTests,
    setMeta,
} from '../../src/lib/db/db.ts';
import { setupTestServer } from '../utils/server.ts';

setupTestServer();

/** Creates the M2 key-value database with one `kv` store, as src/lib/storage/kv.ts did. */
function seedLegacy(entries: Record<string, unknown>): Promise<void> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(LEGACY_DB_NAME, 1);
        request.onupgradeneeded = () => {
            request.result.createObjectStore('kv');
        };
        request.onerror = () => {
            reject(request.error ?? new Error('open failed'));
        };
        request.onsuccess = () => {
            const legacy = request.result;
            const tx = legacy.transaction('kv', 'readwrite');
            for (const [key, value] of Object.entries(entries)) {
                tx.objectStore('kv').put(value, key);
            }
            tx.oncomplete = () => {
                legacy.close();
                resolve();
            };
        };
    });
}

async function legacyExists(): Promise<boolean> {
    const databases = await indexedDB.databases();
    return databases.some((database) => database.name === LEGACY_DB_NAME);
}

describe('offline store', () => {
    it('moves the M2 device record into meta and deletes the legacy database', async () => {
        await clearAllTables();
        await seedLegacy({ device: { deviceUuid: 'dev-1', secret: 's3cret' } });
        resetMigrationForTests();

        expect(await getMeta('device')).toEqual({ deviceUuid: 'dev-1', secret: 's3cret' });
        expect(await legacyExists()).toBe(false);
    });

    it('keeps a newer device record and ignores malformed legacy values', async () => {
        await setMeta('device', { deviceUuid: 'dev-new', secret: null });
        await seedLegacy({ device: { deviceUuid: 'dev-old', secret: 'x' } });
        resetMigrationForTests();
        expect(await getMeta('device')).toEqual({ deviceUuid: 'dev-new', secret: null });

        await clearAllTables();
        await seedLegacy({ device: 'not a record' });
        resetMigrationForTests();
        expect(await getMeta('device')).toBeUndefined();
    });

    it('does not create the legacy database when there is none', async () => {
        resetMigrationForTests();
        await getMeta('seq');
        expect(await legacyExists()).toBe(false);
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
