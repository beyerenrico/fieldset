/**
 * The offline store (ADR 0035). Orchestrator-owned: request schema changes (a new `version(n)` with an
 * upgrade) instead of editing row shapes or a shipped version.
 */
import Dexie, { type Table } from 'dexie';
import type {
    AttachmentRow,
    DeviceRecord,
    DraftRow,
    FormRow,
    FormVersionRow,
    MetaKey,
    MetaRow,
    MetaValues,
    OutboxRow,
} from './schema.ts';

export const DB_NAME = 'fsa-offline';
/** M2 raw key-value database (src/lib/storage/kv.ts until M6); it only ever held `device`. */
export const LEGACY_DB_NAME = 'form-schema-field-app';

export type OfflineDb = Dexie & {
    drafts: Table<DraftRow, string>;
    outbox: Table<OutboxRow, string>;
    attachments: Table<AttachmentRow, string>;
    forms: Table<FormRow, [number, number]>;
    formVersions: Table<FormVersionRow, number>;
    meta: Table<MetaRow, MetaKey>;
};

export function createOfflineDb(name: string = DB_NAME): OfflineDb {
    const db = new Dexie(name) as OfflineDb;
    db.version(1).stores({
        drafts: 'uuid, [orgId+userId], updatedAt',
        outbox: 'id, submissionUuid, [orgId+userId], seq, nextAttemptAt',
        attachments: 'uuid, submissionUuid, state',
        forms: '[orgId+id], orgId',
        formVersions: 'id, orgId, [orgId+form_id]',
        meta: 'key',
    });
    return db;
}

export const db: OfflineDb = createOfflineDb();

export async function getMeta<K extends MetaKey>(key: K): Promise<MetaValues[K] | undefined> {
    await migrateLegacyStore();
    return (await db.meta.get(key))?.value as MetaValues[K] | undefined;
}

export async function setMeta<K extends MetaKey>(key: K, value: MetaValues[K]): Promise<void> {
    await migrateLegacyStore();
    await db.meta.put({ key, value });
}

export async function deleteMeta(key: MetaKey): Promise<void> {
    await migrateLegacyStore();
    await db.meta.delete(key);
}

/** Next global outbox order number (atomic across tabs). */
export function nextSeq(): Promise<number> {
    return db.transaction('rw', db.meta, async () => {
        const next = (((await db.meta.get('seq'))?.value as number | undefined) ?? 0) + 1;
        await db.meta.put({ key: 'seq', value: next });
        return next;
    });
}

/** Empties every table in one transaction (logout, wipe, tests); the database itself stays. */
export async function clearAllTables(): Promise<void> {
    await db.transaction('rw', db.tables, async () => {
        await Promise.all(db.tables.map((table) => table.clear()));
    });
}

function isDeviceRecord(value: unknown): value is DeviceRecord {
    if (typeof value !== 'object' || value === null) {
        return false;
    }
    const record = value as Record<string, unknown>;
    return (
        typeof record.deviceUuid === 'string' &&
        (typeof record.secret === 'string' || record.secret === null)
    );
}

function readLegacyRecord(legacy: IDBDatabase, resolve: (value: unknown) => void): void {
    if (!legacy.objectStoreNames.contains('kv')) {
        legacy.close();
        resolve(undefined);
        return;
    }
    const get = legacy.transaction('kv', 'readonly').objectStore('kv').get('device');
    get.onsuccess = () => {
        legacy.close();
        resolve(get.result);
    };
    get.onerror = () => {
        legacy.close();
        resolve(undefined);
    };
}

/** Reads kv/device from the legacy database without creating it when it does not exist. */
function readLegacyDevice(): Promise<unknown> {
    return new Promise((resolve) => {
        const request = indexedDB.open(LEGACY_DB_NAME);
        request.onupgradeneeded = () => {
            request.transaction?.abort(); // It did not exist: do not create it.
        };
        request.onerror = () => {
            resolve(undefined);
        };
        request.onsuccess = () => {
            readLegacyRecord(request.result, resolve);
        };
    });
}

function deleteLegacy(): Promise<void> {
    return new Promise((resolve) => {
        const request = indexedDB.deleteDatabase(LEGACY_DB_NAME);
        request.onsuccess =
            request.onerror =
            request.onblocked =
                () => {
                    resolve(); // blocked: completes once the old tab closes its connection
                };
    });
}

let migration: Promise<void> | null = null;

/** Moves the M2 device record into meta.device and deletes the legacy database (idempotent, memoized). */
export function migrateLegacyStore(): Promise<void> {
    migration ??= (async () => {
        const legacy = await readLegacyDevice();
        if (isDeviceRecord(legacy) && (await db.meta.get('device')) === undefined) {
            await db.meta.put({ key: 'device', value: legacy });
        }
        await deleteLegacy();
    })();
    return migration;
}

/** Tests only: run the migration again. */
export function resetMigrationForTests(): void {
    migration = null;
}
