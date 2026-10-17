/**
 * Attachment rows in the offline store (ADR 0035). A new photo, file or signature is written as an
 * AttachmentRow (bytes as ArrayBuffer) and queued; the sync engine uploads it and records the
 * result. Bytes of uploaded attachments are pruned once their draft can no longer be edited.
 */
import type { AttachmentUpload } from '@form-schema-app/form-schema-react';
import { db } from '../db/db.ts';
import type { AttachmentRow, Scope } from '../db/schema.ts';
import { currentScope } from '../db/scope.ts';
import { enqueueAttachmentUpload } from '../outbox/outbox.ts';
import { onSyncRequested } from '../sync/triggers.ts';

const inScope = (row: AttachmentRow, scope: Scope): boolean =>
    row.orgId === scope.orgId && row.userId === scope.userId;

async function readBytes(blob: Blob): Promise<ArrayBuffer> {
    if (typeof blob.arrayBuffer === 'function') {
        return blob.arrayBuffer();
    }
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
            resolve(reader.result as ArrayBuffer);
        };
        reader.onerror = () => {
            reject(reader.error ?? new Error('The file could not be read.'));
        };
        reader.readAsArrayBuffer(blob);
    });
}

/** Stores the file and queues its upload; the row is removed again when queueing fails. */
export async function storeAttachment(
    scope: Scope,
    submissionUuid: string,
    uuid: string,
    request: AttachmentUpload,
): Promise<AttachmentRow> {
    const bytes = await readBytes(request.file);
    const row: AttachmentRow = {
        ...scope,
        uuid,
        submissionUuid,
        kind: request.kind,
        elementKey: request.elementKey,
        fileName: request.fileName,
        mime: request.file.type === '' ? 'application/octet-stream' : request.file.type,
        size: bytes.byteLength,
        bytes,
        state: 'pending',
        error: null,
        record: null,
        createdAt: Date.now(),
    };
    await db.attachments.put(row);
    try {
        await enqueueAttachmentUpload(scope, submissionUuid, uuid);
    } catch (error) {
        await db.attachments.delete(uuid);
        throw error;
    }
    return row;
}

export async function listLocalAttachments(
    scope: Scope,
    submissionUuid: string,
): Promise<AttachmentRow[]> {
    return db.attachments
        .where('submissionUuid')
        .equals(submissionUuid)
        .filter((row) => inScope(row, scope))
        .toArray();
}

/** Local bytes of an attachment of the current user, if still on the device. */
export async function localBlob(uuid: string): Promise<Blob | null> {
    const scope = currentScope();
    const row = scope === null ? undefined : await db.attachments.get(uuid);
    if (scope === null || row === undefined || !inScope(row, scope) || row.bytes === null) {
        return null;
    }
    return new Blob([row.bytes], { type: row.mime });
}

/**
 * Forgets an attachment the user removed before it was uploaded (the engine drops an upload op
 * whose row is gone). Uploaded ones stay: the server keeps them until the data no longer refers.
 */
export async function removeLocalAttachment(scope: Scope, uuid: string): Promise<boolean> {
    return db.transaction('rw', db.attachments, async () => {
        const row = await db.attachments.get(uuid);
        if (row === undefined || !inScope(row, scope) || row.state === 'uploaded') {
            return false;
        }
        await db.attachments.delete(uuid);
        return true;
    });
}

/**
 * Drops the bytes (bytes: null) of uploaded attachments whose draft is no longer editable (submitted)
 * or no longer on the device; previews then come from the server. Limited to one submission when
 * `submissionUuid` is given (call it after a successful submit).
 */
export async function pruneUploadedAttachments(
    scope: Scope,
    submissionUuid?: string,
): Promise<number> {
    return db.transaction('rw', db.attachments, db.drafts, async () => {
        const rows = await db.attachments
            .where('state')
            .equals('uploaded')
            .filter(
                (row) =>
                    row.bytes !== null &&
                    inScope(row, scope) &&
                    (submissionUuid === undefined || row.submissionUuid === submissionUuid),
            )
            .toArray();
        let pruned = 0;
        for (const row of rows) {
            const draft = await db.drafts.get(row.submissionUuid);
            if (draft === undefined || draft.server?.can_edit === false) {
                await db.attachments.put({ ...row, bytes: null });
                pruned++;
            }
        }
        return pruned;
    });
}

/** Prunes on every sync request except enqueues (they happen on each autosave). */
export function startAttachmentPruning(): () => void {
    return onSyncRequested((reason) => {
        const scope = currentScope();
        if (scope !== null && reason !== 'enqueue') {
            pruneUploadedAttachments(scope).catch((error: unknown) => {
                console.error('Pruning uploaded attachments failed', error);
            });
        }
    });
}
