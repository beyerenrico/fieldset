/**
 * §9.2 AC "syncs exactly once" at the engine level: an offline fill with 30 lines and two photos,
 * a queued submit, then online. Lost responses retry with the stored Idempotency-Key.
 */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import { http } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AutosaveController } from '../../../src/lib/autosave/controller.ts';
import { createLocalTransport } from '../../../src/lib/autosave/local-transport.ts';
import { db } from '../../../src/lib/db/db.ts';
import { enqueueAttachmentUpload, enqueueSubmit, opId } from '../../../src/lib/outbox/outbox.ts';
import { setOrganizationId } from '../../../src/lib/session/org-store.ts';
import { runSync } from '../../../src/lib/sync/engine.ts';
import { createSyncBackend, fakeUploads, summarize } from '../../utils/a-backend.ts';
import { SCOPE, attachmentRow, setupSyncScope } from '../../utils/a-store.ts';
import { server, setupTestServer } from '../../utils/server.ts';

setupTestServer();
setupSyncScope();

const UUID = '0192f1c4-0000-7000-8000-00000000e001';
const PHOTOS = ['0192f1c4-0000-7000-8000-00000000e0a1', '0192f1c4-0000-7000-8000-00000000e0a2'];
const SUBMIT_ID = opId('submit', SCOPE.userId, UUID);

let online = true;

beforeEach(() => {
    online = true;
    Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => online });
    setOrganizationId(5); // the selected organization is not the row's: requests use the row's
});
afterEach(() => {
    Reflect.deleteProperty(navigator, 'onLine');
    vi.restoreAllMocks();
});

const lines = (): JsonObject[] =>
    Array.from({ length: 30 }, (_, index) => ({ name: `Line ${String(index + 1)}` }));

function editor() {
    const transport = createLocalTransport({
        scope: SCOPE,
        uuid: UUID,
        formId: 3,
        formVersionId: 7,
        server: null,
    });
    return new AutosaveController({ uuid: UUID, formVersionId: 7, data: {}, transport });
}

/** Offline: fill 30 lines, add two photos (stored like the attachment adapter does), submit. */
async function fillOffline(): Promise<AutosaveController> {
    online = false;
    const autosave = editor();
    autosave.update({ customer: 'ACME', items: lines() });
    await autosave.flush();
    for (const uuid of PHOTOS) {
        await db.attachments.put(attachmentRow({ uuid, submissionUuid: UUID }));
        await enqueueAttachmentUpload(SCOPE, UUID, uuid);
    }
    autosave.update({ customer: 'ACME', items: lines(), photos: PHOTOS });
    await autosave.flush();
    await enqueueSubmit(SCOPE, UUID, '2026-10-17T09:41:00.000Z');
    expect(await runSync({ manual: true })).toMatchObject({ ran: true, sent: 0, state: 'offline' });
    online = true;
    return autosave;
}

function setup() {
    const backend = createSyncBackend();
    server.use(...backend.handlers);
    fakeUploads(backend.state);
    return backend;
}

/** The Idempotency-Key persisted on the submit op at the moment the request leaves. */
function watchStoredKeys(): (string | null)[] {
    const keys: (string | null)[] = [];
    server.use(
        http.post('*/api/v1/submissions/:uuid/submit', async () => {
            const op = await db.outbox.get(SUBMIT_ID);
            keys.push(op?.kind === 'submit' ? (op.request?.idempotencyKey ?? null) : null);
            return undefined; // fall through to the fake backend
        }),
    );
    return keys;
}

describe('offline fill → online: syncs exactly once', () => {
    it('sends create PUT, both uploads and one submit with the stored key, then nothing', async () => {
        const backend = setup();
        const stored = watchStoredKeys();
        await fillOffline();
        expect(backend.state.log).toEqual([]);

        const result = await runSync({ manual: true });

        expect(result).toMatchObject({
            ran: true,
            sent: 4,
            blocked: 0,
            remaining: 0,
            state: 'idle',
        });
        expect(summarize(backend.state.log)).toEqual(['PUT create', 'UPLOAD', 'UPLOAD', 'SUBMIT']);
        expect(backend.state.log.every((entry) => entry.org === '1')).toBe(true);
        const submits = backend.state.log.filter((entry) => entry.path.endsWith('/submit'));
        expect(submits[0]?.key).toBe(stored[0]);
        expect(submits[0]?.body).toEqual({
            revision: 1,
            client_submitted_at: '2026-10-17T09:41:00.000Z',
        });
        expect(backend.state.log[0]?.body).toMatchObject({
            form_version_id: 7,
            data: { customer: 'ACME', photos: PHOTOS },
        });
        expect(
            (backend.state.log[0]?.body as { data: { items: unknown[] } }).data.items,
        ).toHaveLength(30);
        expect(backend.state.submissions.get(UUID)).toMatchObject({
            status: 'submitted',
            number: 'SR-2026-00001',
        });
        // Fully synced and submitted: the draft and its photo bytes leave the device.
        expect(await db.drafts.get(UUID)).toBeUndefined();
        expect(await db.attachments.count()).toBe(0);
        expect(await db.outbox.count()).toBe(0);

        const again = await runSync({ manual: true });
        expect(again).toMatchObject({ sent: 0, remaining: 0 });
        expect(backend.state.log).toHaveLength(4);
    });

    it('sends the update PUT only when the draft changed after the create', async () => {
        const backend = setup();
        const autosave = await fillOffline();
        server.use(
            http.put('*/api/v1/submissions/:uuid', async () => {
                if (backend.state.log.length === 0) {
                    // An edit lands while the create is on its way.
                    autosave.update({ customer: 'ACME GmbH', items: lines(), photos: PHOTOS });
                    await autosave.flush();
                }
                return undefined;
            }),
        );

        await runSync({ manual: true });

        expect(summarize(backend.state.log)).toEqual([
            'PUT create',
            'UPLOAD',
            'UPLOAD',
            'PUT update',
            'SUBMIT',
        ]);
        expect(backend.state.log[3]?.body).toMatchObject({
            revision: 1,
            data: { customer: 'ACME GmbH' },
        });
        expect(backend.state.log[4]?.body).toMatchObject({ revision: 2 });
        expect(backend.state.submissions.get(UUID)?.data).toMatchObject({ customer: 'ACME GmbH' });
    });

    it('retries a processed submit whose answer was lost with the same key (one number)', async () => {
        const backend = setup();
        await fillOffline();
        backend.state.loseNextSubmit = true;

        const first = await runSync({ manual: true });
        expect(first.state).toBe('offline');
        const op = await db.outbox.get(SUBMIT_ID);
        const key = op?.kind === 'submit' ? op.request?.idempotencyKey : undefined;
        expect(key).toMatch(/^[0-9a-f-]{36}$/);
        expect(op?.lastError).toMatchObject({ code: 'network' });

        const second = await runSync({ manual: true });

        expect(second).toMatchObject({ remaining: 0, state: 'idle' });
        const submits = backend.state.log.filter((entry) => entry.path.endsWith('/submit'));
        expect(submits.map((entry) => entry.key)).toEqual([key, key]);
        expect(submits[1]?.body).toEqual(submits[0]?.body);
        expect(backend.state.replays.size).toBe(1);
        expect(backend.state.submissions.get(UUID)?.number).toBe('SR-2026-00001');
        expect(summarize(backend.state.log).filter((name) => name !== 'SUBMIT')).toEqual([
            'PUT create',
            'UPLOAD',
            'UPLOAD',
        ]);
    });

    it('adopts a lost PUT answer instead of opening a conflict', async () => {
        const backend = setup();
        backend.state.submissions.set(UUID, {
            ...(await import('../../utils/fixtures.ts')).makeSubmission({
                uuid: UUID,
                revision: 3,
                data: { customer: 'ACME', items: lines() },
            }),
        });
        const autosave = editor();
        autosave.update({ customer: 'ACME', items: lines() });
        await autosave.flush();

        await runSync({ manual: true });

        // The server already had exactly this data (our write, its answer lost): adopted.
        const row = await db.drafts.get(UUID);
        expect(row).toMatchObject({ sync: 'synced', serverRevision: 3, conflict: null });
        expect(await db.outbox.count()).toBe(0);
    });
});
