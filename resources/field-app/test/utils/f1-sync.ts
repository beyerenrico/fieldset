/** Fixtures of the M6 gate fixes (agent F1): backend with reads, server drafts, local edits. */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import { createLocalTransport } from '../../src/lib/autosave/local-transport.ts';
import type { Submission } from '../../src/lib/api/types.ts';
import { db } from '../../src/lib/db/db.ts';
import type { DraftRow, OutboxRow } from '../../src/lib/db/schema.ts';
import { createSyncBackend, fakeUploads, readHandlers } from './a-backend.ts';
import { SCOPE, draftRow } from './a-store.ts';
import { makeSubmission } from './fixtures.ts';
import { server } from './server.ts';

export const UUID = '0192f1c4-0000-7000-8000-00000000f101';
export const PHOTO = '0192f1c4-0000-7000-8000-00000000f1a1';
export const SUBMITTED_AT = '2026-10-17T10:00:00.000Z';

/** The fake write endpoints, uploads and `GET /submissions/{uuid}`. */
export function setupBackend(overrides: Parameters<typeof createSyncBackend>[0] = {}) {
    const backend = createSyncBackend(overrides);
    server.use(...backend.handlers, ...readHandlers(backend.state));
    fakeUploads(backend.state);
    return backend;
}

export type Backend = ReturnType<typeof setupBackend>;

/**
 * A draft on the server at `revision` with `data`, and its clean local row (nothing to send).
 * Returns the server copy.
 */
export async function serverDraft(
    backend: Backend,
    data: JsonObject,
    overrides: Partial<Submission> = {},
    row: Partial<DraftRow> = {},
): Promise<Submission> {
    const submission = makeSubmission({ uuid: UUID, revision: 1, data, ...overrides });
    backend.state.submissions.set(UUID, submission);
    await db.drafts.put(
        draftRow({
            uuid: UUID,
            data,
            localVersion: 1,
            syncedVersion: 1,
            serverRevision: submission.revision,
            server: submission,
            sync: 'synced',
            ...row,
        }),
    );
    return submission;
}

/** An edit as the editor writes it (DraftRow localVersion + 1 and a coalesced draft.save op). */
export async function editLocally(data: JsonObject, server: Submission | null = null) {
    const transport = createLocalTransport({
        scope: SCOPE,
        uuid: UUID,
        formId: 3,
        formVersionId: 7,
        server,
    });
    return transport.save({ uuid: UUID, formVersionId: 7, data });
}

export async function opsOfDraft(uuid: string = UUID): Promise<OutboxRow[]> {
    return db.outbox.where('submissionUuid').equals(uuid).toArray();
}

export function keyOf(op: OutboxRow | undefined): string | null {
    if (op?.kind === 'send') {
        return op.request.idempotencyKey;
    }
    return op?.kind === 'submit' ? (op.request?.idempotencyKey ?? null) : null;
}
