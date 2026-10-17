/**
 * Lost answers and late edits (M6 gate F1 items 1, 3, 4): a submit never marks unsent edits as
 * synced, a lost PUT (or create) answer is adopted after more edits, and a lost submit answer
 * finishes the submit instead of a conflict, not_editable or a duplicate offer.
 */
import { http } from 'msw';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { db } from '../../../src/lib/db/db.ts';
import { enqueueDraftSave, enqueueSubmit } from '../../../src/lib/outbox/outbox.ts';
import { runSync } from '../../../src/lib/sync/engine.ts';
import { onOutboxEvent, type OutboxEvent } from '../../../src/lib/sync/events.ts';
import { summarize } from '../../utils/a-backend.ts';
import { SCOPE, draftRow, setupSyncScope } from '../../utils/a-store.ts';
import {
    SUBMITTED_AT,
    UUID,
    editLocally,
    opsOfDraft,
    serverDraft,
    setupBackend,
} from '../../utils/f1-sync.ts';
import { makeSubmission } from '../../utils/fixtures.ts';
import { server, setupTestServer } from '../../utils/server.ts';

setupTestServer();
setupSyncScope();

afterEach(() => {
    vi.restoreAllMocks();
});

describe('a submit never marks unsent edits as synced (item 1)', () => {
    it('sends an edit written after the draft steps before the submit', async () => {
        const backend = setupBackend();
        const base = await serverDraft(backend, { customer: 'A' });
        await editLocally({ customer: 'B' }, base);
        await enqueueSubmit(SCOPE, UUID, SUBMITTED_AT);
        let puts = 0;
        server.use(
            http.put('*/api/v1/submissions/:uuid', async () => {
                puts++;
                if (puts === 1) {
                    await editLocally({ customer: 'C' }, base); // typed while the PUT is on its way
                }
                return undefined;
            }),
        );

        await runSync({ manual: true });

        expect(summarize(backend.state.log)).toEqual(['PUT update', 'PUT update', 'SUBMIT']);
        expect(backend.state.log[2]?.body).toMatchObject({ revision: 3 });
        expect(backend.state.submissions.get(UUID)).toMatchObject({
            status: 'submitted',
            data: { customer: 'C' },
        });
        expect(await db.drafts.get(UUID)).toBeUndefined(); // submitted and synced: pruned
        expect(await db.outbox.count()).toBe(0);
    });

    it('keeps an edit written while the submit is on its way dirty, with its draft.save op', async () => {
        const backend = setupBackend();
        const base = await serverDraft(backend, { customer: 'A' });
        await enqueueSubmit(SCOPE, UUID, SUBMITTED_AT);
        server.use(
            http.post('*/api/v1/submissions/:uuid/submit', async () => {
                await editLocally({ customer: 'Late' }, base);
                return undefined;
            }),
        );

        await runSync({ manual: true });

        const row = await db.drafts.get(UUID);
        expect(row).toMatchObject({ localVersion: 2, syncedVersion: 1, serverRevision: 2 });
        expect(row?.server?.status).toBe('submitted');
        expect((await opsOfDraft()).map((op) => op.kind)).toEqual(['draft.save']);
    });
});

describe('lost PUT answers followed by more edits (item 3)', () => {
    it('adopts the lost update and sends the newer data on top, without a conflict', async () => {
        const backend = setupBackend();
        const base = await serverDraft(backend, { customer: 'A' });
        await editLocally({ customer: 'B' }, base);
        await enqueueDraftSave(SCOPE, UUID);
        backend.state.loseNextPut = true;
        expect(await runSync({ manual: true })).toMatchObject({ state: 'offline' });
        expect(backend.state.submissions.get(UUID)).toMatchObject({ revision: 2 });

        await editLocally({ customer: 'C' }, base);
        await runSync({ manual: true });

        expect(summarize(backend.state.log)).toEqual(['PUT update', 'PUT update', 'PUT update']);
        expect(backend.state.log[2]?.body).toMatchObject({ revision: 2, data: { customer: 'C' } });
        expect(backend.state.submissions.get(UUID)).toMatchObject({
            revision: 3,
            data: { customer: 'C' },
        });
        expect(await db.drafts.get(UUID)).toMatchObject({
            sync: 'synced',
            conflict: null,
            serverRevision: 3,
            localVersion: 3,
            syncedVersion: 3,
            sentSaves: [],
        });
        expect(await db.outbox.count()).toBe(0);
    });

    it('adopts a lost create the same way', async () => {
        const backend = setupBackend();
        await db.drafts.put(draftRow({ uuid: UUID, data: { customer: 'A' } }));
        await enqueueDraftSave(SCOPE, UUID);
        backend.state.loseNextPut = true;
        await runSync({ manual: true });
        expect(backend.state.submissions.get(UUID)).toMatchObject({ revision: 1 });

        await editLocally({ customer: 'B' });
        await runSync({ manual: true });

        expect(summarize(backend.state.log)).toEqual(['PUT create', 'PUT create', 'PUT update']);
        expect(backend.state.submissions.get(UUID)).toMatchObject({
            revision: 2,
            data: { customer: 'B' },
        });
        expect(await db.drafts.get(UUID)).toMatchObject({ sync: 'synced', serverRevision: 2 });
    });

    it('still opens a conflict when someone else changed the draft', async () => {
        const backend = setupBackend();
        const base = await serverDraft(backend, { customer: 'A' });
        await editLocally({ customer: 'B' }, base);
        await enqueueDraftSave(SCOPE, UUID);
        backend.state.loseNextPut = true;
        await runSync({ manual: true });
        backend.state.submissions.set(UUID, { ...base, revision: 3, data: { customer: 'Office' } });

        await runSync({ manual: true });

        expect(await db.drafts.get(UUID)).toMatchObject({
            sync: 'conflict',
            conflict: { revision: 3 },
        });
    });
});

describe('lost submit answers (item 4)', () => {
    it('finishes the submit when the user edited after it (draft 409), one number', async () => {
        const backend = setupBackend();
        const base = await serverDraft(backend, { customer: 'A' });
        await enqueueSubmit(SCOPE, UUID, SUBMITTED_AT);
        backend.state.loseNextSubmit = true;
        await runSync({ manual: true });
        expect(backend.state.submissions.get(UUID)?.status).toBe('submitted');
        await editLocally({ customer: 'After submit' }, base);
        const events: OutboxEvent[] = [];
        const stop = onOutboxEvent((event) => events.push(event));

        const result = await runSync({ manual: true });

        stop();
        expect(result).toMatchObject({ blocked: 0, remaining: 0 });
        expect(summarize(backend.state.log)).toEqual(['SUBMIT', 'PUT update']);
        expect(backend.state.replays.size).toBe(1);
        expect(await db.drafts.get(UUID)).toBeUndefined(); // submitted: it left the device
        expect(await db.outbox.count()).toBe(0);
        expect(events).toContainEqual(
            expect.objectContaining({ type: 'done', kind: 'submit', submissionUuid: UUID }),
        );
    });

    it('finishes the submit on not_editable when the server copy is our submit', async () => {
        const backend = setupBackend();
        await serverDraft(backend, { customer: 'A' }, { revision: 2 });
        await enqueueSubmit(SCOPE, UUID, SUBMITTED_AT);
        // This device's submit went through (its answer was lost), the office moved it on since.
        backend.state.submissions.set(
            UUID,
            makeSubmission({
                uuid: UUID,
                revision: 5,
                status: 'in_review',
                can_edit: false,
                number: 'SR-2026-00009',
                submitted_at: '2026-10-17T10:00:01+00:00',
                client_submitted_at: '2026-10-17T10:00:00+00:00',
            }),
        );

        await runSync({ manual: true });

        expect(summarize(backend.state.log)).toEqual(['SUBMIT', 'GET']);
        expect(await db.drafts.get(UUID)).toBeUndefined();
        expect(await db.outbox.count()).toBe(0);
    });

    it('keeps not_editable when the server copy was submitted by another submit', async () => {
        const backend = setupBackend();
        await serverDraft(backend, { customer: 'A' }, { revision: 2 });
        await enqueueSubmit(SCOPE, UUID, SUBMITTED_AT);
        backend.state.submissions.set(
            UUID,
            makeSubmission({
                uuid: UUID,
                revision: 6,
                status: 'submitted',
                can_edit: false,
                submitted_at: '2026-10-16T08:00:00+00:00',
                client_submitted_at: '2026-10-16T08:00:00+00:00',
            }),
        );

        await runSync({ manual: true });

        expect(summarize(backend.state.log)).toEqual(['SUBMIT', 'GET']);
        expect(await db.drafts.get(UUID)).toMatchObject({
            sync: 'blocked',
            blockedBy: 'not_editable',
        });
    });
});
