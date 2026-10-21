/**
 * Local-first autosave transport (ADR 0035): every save writes the DraftRow (localVersion + 1) and
 * coalesces one `draft.save` op in the same transaction. The sync engine reads the row's data when
 * it sends the op, so the server always gets the latest local state. A write is refused once the
 * session ended (SessionEndedError) and a full storage is reported as StorageFullError.
 */
import type { JsonObject } from '@fieldset/fieldset-js';
import type { Submission } from '../api/types.ts';
import { db } from '../db/db.ts';
import type { DraftRow, Scope } from '../db/schema.ts';
import { getCurrentUserId } from '../db/scope.ts';
import { afterEnqueue, putDraftSaveOp } from '../outbox/outbox.ts';
import { sessionUserId } from '../sync/session-guard.ts';
import { isQuotaExceeded, SessionEndedError, StorageFullError } from './errors.ts';
import type { SaveReceipt, SaveRequest, SaveTransport } from './types.ts';

export interface LocalDraftInit {
    readonly scope: Scope;
    readonly uuid: string;
    readonly formId: number;
    readonly formVersionId: number;
    /** Server copy the editor started from (null: the draft is not on the server yet). */
    readonly server: Submission | null;
}

function newRow(init: LocalDraftInit, data: JsonObject, now: number): DraftRow {
    const server = init.server;
    return {
        uuid: init.uuid,
        orgId: init.scope.orgId,
        userId: init.scope.userId,
        formId: init.formId,
        formVersionId: init.formVersionId,
        data,
        localVersion: 0,
        syncedVersion: 0,
        serverRevision: server?.revision ?? 0,
        server,
        conflict: null,
        sync: 'queued',
        blockedBy: null,
        submitErrors: [],
        createdAt: now,
        updatedAt: now,
    };
}

/** A clean row behind a newer server copy (opened online) takes over its revision first. */
function rebase(row: DraftRow, server: Submission | null): DraftRow {
    const clean = row.localVersion <= row.syncedVersion;
    return server !== null && clean && server.revision > row.serverRevision
        ? { ...row, serverRevision: server.revision, server }
        : row;
}

/** The row written for an edit: dirty, queued (a paused draft stays paused, its errors go). */
function edited(row: DraftRow, data: JsonObject, now: number): DraftRow {
    const paused = row.sync === 'conflict' || row.sync === 'blocked';
    return {
        ...row,
        data,
        localVersion: row.localVersion + 1,
        submitErrors: [],
        sync: paused ? row.sync : 'queued',
        updatedAt: now,
    };
}

/** ensureOnly: a draft started from its server copy exists without being dirty. */
function ensured(row: DraftRow): DraftRow {
    const synced = row.server !== null;
    return {
        ...row,
        localVersion: 1,
        syncedVersion: synced ? 1 : 0,
        sync: synced ? 'synced' : 'queued',
    };
}

/**
 * Inside the write transaction: the draft's user is still this tab's user and the last signed-in
 * user (a logout, wipe or another login in another tab ends the session; nothing is written back).
 */
async function assertSession(userId: number): Promise<void> {
    if (getCurrentUserId() !== userId || (await sessionUserId()) !== userId) {
        throw new SessionEndedError();
    }
}

async function write(
    init: LocalDraftInit,
    request: SaveRequest,
): Promise<SaveReceipt & { queued: boolean }> {
    return db.transaction('rw', db.drafts, db.outbox, db.meta, async () => {
        await assertSession(init.scope.userId);
        const now = Date.now();
        const existing = await db.drafts.get(init.uuid);
        if (existing !== undefined && request.ensureOnly === true) {
            return { localVersion: existing.localVersion, queued: false };
        }
        const base = rebase(existing ?? newRow(init, request.data, now), init.server);
        const next = request.ensureOnly === true ? ensured(base) : edited(base, request.data, now);
        await db.drafts.put(next);
        const queued = next.localVersion > next.syncedVersion;
        if (queued) {
            await putDraftSaveOp(init.scope, init.uuid);
        }
        return { localVersion: next.localVersion, queued };
    });
}

/** The write, with a full storage reported as StorageFullError (Dexie wraps the DOMException). */
async function writeOrExplain(
    init: LocalDraftInit,
    request: SaveRequest,
): Promise<SaveReceipt & { queued: boolean }> {
    try {
        return await write(init, request);
    } catch (error) {
        throw isQuotaExceeded(error) ? new StorageFullError(error) : error;
    }
}

export function createLocalTransport(init: LocalDraftInit): SaveTransport {
    return {
        save: async (request) => {
            const { localVersion, queued } = await writeOrExplain(init, request);
            if (queued) {
                afterEnqueue();
            }
            return { localVersion };
        },
    };
}
