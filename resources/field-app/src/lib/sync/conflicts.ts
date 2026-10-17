/**
 * The user's decisions on paused submissions (ADR 0035): "Keep mine" / "Use server" after a
 * revision conflict, retry after a blocking answer, discard, or copy into a new draft.
 */
import { db, nextSeq } from '../db/db.ts';
import type { AttachmentRow, DraftRow } from '../db/schema.ts';
import { newId } from '../ids.ts';
import { afterEnqueue, opId, putDraftSaveOp } from '../outbox/outbox.ts';
import { requestSync } from './triggers.ts';

const TABLES = [db.drafts, db.outbox, db.attachments, db.meta] as const;

/** Clears the backoff of every op of the submission (a decision is worth an immediate try). */
async function wakeOps(uuid: string): Promise<void> {
    const ops = await db.outbox.where('submissionUuid').equals(uuid).toArray();
    await db.outbox.bulkPut(
        ops.map((op) => ({ ...op, state: 'pending' as const, nextAttemptAt: 0, attempts: 0 })),
    );
}

function keepMine(row: DraftRow): DraftRow {
    const server = row.conflict ?? row.server;
    const dirty = row.localVersion > row.syncedVersion;
    return {
        ...row,
        serverRevision: server?.revision ?? row.serverRevision,
        server,
        conflict: null,
        sync: 'queued',
        // My data is pushed again on top of the server revision.
        localVersion: dirty ? row.localVersion : row.localVersion + 1,
    };
}

function useServer(row: DraftRow): DraftRow {
    const server = row.conflict ?? row.server;
    if (server === null) {
        return row;
    }
    const version = row.localVersion + 1;
    return {
        ...row,
        data: server.data,
        localVersion: version,
        syncedVersion: version,
        serverRevision: server.revision,
        server,
        conflict: null,
        sync: 'synced',
    };
}

/** Applies the choice; the controller of an open editor adopts the server data itself first. */
export async function resolveConflict(
    submissionUuid: string,
    choice: 'mine' | 'server',
): Promise<void> {
    await db.transaction('rw', TABLES, async () => {
        const row = await db.drafts.get(submissionUuid);
        if (row === undefined || row.conflict === null) {
            return;
        }
        const next = choice === 'mine' ? keepMine(row) : useServer(row);
        await db.drafts.put(next);
        if (choice === 'mine') {
            await putDraftSaveOp(row, submissionUuid);
        } else {
            await db.outbox.delete(opId('draft.save', submissionUuid));
        }
        await wakeOps(submissionUuid);
    });
    requestSync('manual');
}

/** "Try again" after a blocking answer (access regained, the office reopened the submission, …). */
export async function retryBlocked(submissionUuid: string): Promise<void> {
    await db.transaction('rw', TABLES, async () => {
        const row = await db.drafts.get(submissionUuid);
        if (row?.sync === 'blocked') {
            await db.drafts.put({ ...row, sync: 'queued', blockedBy: null });
        }
        await wakeOps(submissionUuid);
    });
    requestSync('manual');
}

async function deleteSubmissionRows(uuid: string): Promise<void> {
    await db.drafts.delete(uuid);
    await db.outbox.where('submissionUuid').equals(uuid).delete();
    await db.attachments.where('submissionUuid').equals(uuid).delete();
}

/** Drops everything this device holds for the submission (the server copy stays as it is). */
export async function discardLocalSubmission(submissionUuid: string): Promise<void> {
    await db.transaction('rw', TABLES, async () => {
        await deleteSubmissionRows(submissionUuid);
    });
}

export interface CopyTarget {
    readonly formId: number;
    readonly formVersionId: number;
}

/** Attachments with local bytes get new uuids (a uuid belongs to one submission on the server). */
function copyAttachments(
    rows: readonly AttachmentRow[],
    submissionUuid: string,
): { copies: AttachmentRow[]; renames: Map<string, string> } {
    const renames = new Map<string, string>();
    const copies = rows
        .filter((row) => row.bytes !== null)
        .map((row) => {
            const uuid = newId();
            renames.set(row.uuid, uuid);
            return {
                ...row,
                uuid,
                submissionUuid,
                state: 'pending' as const,
                error: null,
                record: null,
            };
        });
    return { copies, renames };
}

function renameReferences(row: DraftRow, renames: ReadonlyMap<string, string>): DraftRow['data'] {
    let json = JSON.stringify(row.data);
    for (const [from, to] of renames) {
        json = json.split(from).join(to); // uuids are unique: no partial matches
    }
    return JSON.parse(json) as DraftRow['data'];
}

/**
 * "Save as a new draft" (not_editable) and "Copy to the current version" (version_retired): the
 * local data moves into a new submission (new uuid, element keys are stable, §7.6).
 */
export async function copyToNewDraft(submissionUuid: string, target?: CopyTarget): Promise<string> {
    const uuid = newId();
    await db.transaction('rw', TABLES, async () => {
        const row = await db.drafts.get(submissionUuid);
        if (row === undefined) {
            throw new Error('Nothing to copy: the draft is not on this device.');
        }
        const attachments = await db.attachments
            .where('submissionUuid')
            .equals(submissionUuid)
            .toArray();
        const { copies, renames } = copyAttachments(attachments, uuid);
        const now = Date.now();
        await db.drafts.put({
            ...row,
            uuid,
            formId: target?.formId ?? row.formId,
            formVersionId: target?.formVersionId ?? row.formVersionId,
            data: renameReferences(row, renames),
            localVersion: 1,
            syncedVersion: 0,
            serverRevision: 0,
            server: null,
            conflict: null,
            sync: 'queued',
            blockedBy: null,
            submitErrors: [],
            createdAt: now,
            updatedAt: now,
        });
        await db.attachments.bulkPut(copies);
        await putDraftSaveOp(row, uuid);
        for (const copy of copies) {
            await db.outbox.put({
                id: opId('attachment.upload', uuid, copy.uuid),
                kind: 'attachment.upload',
                submissionUuid: uuid,
                attachmentUuid: copy.uuid,
                orgId: row.orgId,
                userId: row.userId,
                seq: await nextSeq(),
                state: 'pending',
                attempts: 0,
                nextAttemptAt: 0,
                lastError: null,
                createdAt: now,
            });
        }
        await deleteSubmissionRows(submissionUuid);
    });
    afterEnqueue();
    return uuid;
}
