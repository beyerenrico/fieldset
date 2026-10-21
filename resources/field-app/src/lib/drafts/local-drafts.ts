/**
 * Drafts on this device (ADR 0035): which copy an editor starts from, and a Submission-shaped view
 * of a local row for lists and the detail screen. Every read is filtered by organization and user.
 */
import type { JsonObject } from '@fieldset/fieldset-js';
import type { Submission } from '../api/types.ts';
import { db } from '../db/db.ts';
import type { DraftRow, OutboxRow, Scope } from '../db/schema.ts';

export const inScope = (row: { orgId: number; userId: number }, scope: Scope): boolean =>
    row.orgId === scope.orgId && row.userId === scope.userId;

/** The local row of a draft, or null (also for rows of another user or organization). */
export async function readLocalDraft(uuid: string, scope: Scope): Promise<DraftRow | null> {
    const row = await db.drafts.get(uuid);
    return row !== undefined && inScope(row, scope) ? row : null;
}

export async function localDraftsOf(scope: Scope): Promise<DraftRow[]> {
    return db.drafts.where('[orgId+userId]').equals([scope.orgId, scope.userId]).toArray();
}

export async function opsOfScope(scope: Scope): Promise<OutboxRow[]> {
    return db.outbox.where('[orgId+userId]').equals([scope.orgId, scope.userId]).toArray();
}

/** Unsynced local edits, or the server copy is not newer than the one the edits are based on. */
export function isLocalNewer(local: DraftRow, server: Submission): boolean {
    return local.localVersion > local.syncedVersion || local.serverRevision >= server.revision;
}

export interface DraftStart {
    readonly formId: number;
    readonly formVersionId: number;
    /** Data the editor starts with. */
    readonly data: JsonObject;
    /** Latest server copy known (fetched now, or the one stored with the local row). */
    readonly submission: Submission | null;
    readonly local: DraftRow | null;
}

export interface NewDraftForm {
    readonly formId?: number | undefined;
    readonly versionId?: number | undefined;
}

/** A reopened draft starts from the local row when that row is newer than the server copy. */
export function chooseStart(
    local: DraftRow | null,
    server: Submission | null,
    form: NewDraftForm = {},
): DraftStart | null {
    const formId = server?.form_id ?? local?.formId ?? form.formId;
    const formVersionId = server?.form_version_id ?? local?.formVersionId ?? form.versionId;
    if (formId === undefined || formVersionId === undefined) {
        return null;
    }
    const useLocal = local !== null && (server === null || isLocalNewer(local, server));
    return {
        formId,
        formVersionId,
        data: useLocal ? local.data : (server?.data ?? {}),
        submission: server ?? local?.server ?? null,
        local,
    };
}

/** A Submission-shaped view of a local row (status and number from the last server copy). */
export function localSubmission(row: DraftRow): Submission {
    const server = row.server;
    return {
        uuid: row.uuid,
        form_id: row.formId,
        form_version_id: row.formVersionId,
        version: server?.version ?? 0,
        status: server?.status ?? 'draft',
        revision: row.serverRevision,
        number: server?.number ?? null,
        data: row.data,
        attachments: server?.attachments ?? [],
        submitted_at: server?.submitted_at ?? null,
        client_submitted_at: server?.client_submitted_at ?? null,
        created_at: server?.created_at ?? new Date(row.createdAt).toISOString(),
        updated_at: new Date(row.updatedAt).toISOString(),
        can_edit: server?.can_edit ?? true,
        can_send: server?.can_send ?? false,
        review: server?.review ?? null,
        open_task_id: server?.open_task_id ?? null,
    };
}
