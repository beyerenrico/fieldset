/**
 * The offline store (ADR 0035). Orchestrator-owned: request schema changes (a new `version(n)` with an
 * upgrade) instead of editing row shapes or a shipped version.
 */
import Dexie, { type Table } from 'dexie';
import type {
    AttachmentRow,
    DraftRow,
    FormRow,
    FormVersionRow,
    MetaKey,
    MetaRow,
    MetaValues,
    OutboxRow,
} from './schema.ts';

export const DB_NAME = 'fieldset-offline';

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
    return (await db.meta.get(key))?.value as MetaValues[K] | undefined;
}

export async function setMeta<K extends MetaKey>(key: K, value: MetaValues[K]): Promise<void> {
    await db.meta.put({ key, value });
}

export async function deleteMeta(key: MetaKey): Promise<void> {
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
