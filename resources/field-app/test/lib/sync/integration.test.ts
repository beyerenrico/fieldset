/**
 * M6 integration of the sync engine with the other modules: organization_forbidden through
 * org-access.ts, retired versions from the catalog, removed attachments, stale `syncing` rows.
 */
import { http } from 'msw';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { db, setMeta } from '../../../src/lib/db/db.ts';
import { getDeviceRecord, saveDeviceRecord } from '../../../src/lib/device/credentials.ts';
import { removeLocalAttachment } from '../../../src/lib/attachments/local-store.ts';
import {
    enqueueAttachmentUpload,
    enqueueDraftSave,
    enqueueSend,
    enqueueSubmit,
    opId,
} from '../../../src/lib/outbox/outbox.ts';
import { onAuthLost } from '../../../src/lib/session/auth-events.ts';
import { hardReload } from '../../../src/lib/session/reload.ts';
import { enqueueAndRun } from '../../../src/lib/submit/queue.ts';
import { runSync } from '../../../src/lib/sync/engine.ts';
import { onOutboxEvent, type OutboxEvent } from '../../../src/lib/sync/events.ts';
import { createSyncBackend, failWith, fakeUploads, summarize } from '../../utils/a-backend.ts';
import { SCOPE, attachmentRow, draftRow, setupSyncScope } from '../../utils/a-store.ts';
import { ok } from '../../utils/backend.ts';
import { FORMS, ORG_A, ORG_B, makeMe, makeSubmission, makeVersion } from '../../utils/fixtures.ts';
import { server, setupTestServer } from '../../utils/server.ts';

vi.mock('../../../src/lib/session/reload.ts', () => ({
    LOGIN_PATH: '/app/login',
    hardReload: vi.fn(),
}));

setupTestServer();
setupSyncScope();

const UUID = '0192f1c4-0000-7000-8000-00000000d001';
const PHOTO = '0192f1c4-0000-7000-8000-00000000d0a1';
const SAVE_ID = opId('draft.save', SCOPE.userId, UUID);

afterEach(() => {
    Reflect.deleteProperty(navigator, 'onLine');
    vi.mocked(hardReload).mockClear();
    vi.restoreAllMocks();
});

function setup() {
    const backend = createSyncBackend();
    server.use(...backend.handlers);
    fakeUploads(backend.state);
    return backend;
}

async function seedDraft(overrides: Parameters<typeof draftRow>[0] = {}): Promise<void> {
    await db.drafts.put(draftRow({ uuid: UUID, data: { customer: 'ACME' }, ...overrides }));
    await enqueueDraftSave(SCOPE, UUID);
}

function forbidden(organizations: Parameters<typeof makeMe>[0]) {
    const backend = setup();
    backend.state.inject.set('PUT put', failWith(403, 'organization_forbidden'));
    server.use(http.get('*/api/v1/me', () => ok(makeMe(organizations))));
    return backend;
}

describe('403 organization_forbidden (session/org-access.ts)', () => {
    it('wipes the device when no organization is left and device-status says wipe', async () => {
        forbidden([]);
        server.use(http.post('*/api/v1/auth/device-status', () => ok({ status: 'wipe' })));
        await saveDeviceRecord({ deviceUuid: 'dev-1', secret: 'sec-1' });
        await seedDraft();
        const notices: unknown[] = [];
        const stop = onAuthLost((notice) => notices.push(notice));

        const result = await runSync({ manual: true });

        stop();
        expect(result).toMatchObject({ ran: true, remaining: 0 });
        expect(await db.drafts.count()).toBe(0);
        expect(await db.outbox.count()).toBe(0);
        expect(await getDeviceRecord()).toBeUndefined();
        expect(hardReload).toHaveBeenCalledWith('/app/login?notice=wiped');
        expect(notices).toContain('wiped');
    });

    it("purges the organization's rows and its catalog, and tells a waiting screen", async () => {
        forbidden([ORG_B]);
        await db.forms.put({ ...FORMS[0]!, orgId: 1, cachedAt: 1 });
        await setMeta('catalog:1', { cursor: 'c1', refreshedAt: 1 });
        await seedDraft();
        const events: OutboxEvent[] = [];
        const stop = onOutboxEvent((event) => events.push(event));

        await runSync({ manual: true });

        stop();
        expect(await db.drafts.count()).toBe(0);
        expect(await db.outbox.count()).toBe(0);
        expect(await db.forms.count()).toBe(0);
        expect(await db.meta.get('catalog:1')).toBeUndefined();
        expect(events).toEqual([
            expect.objectContaining({ type: 'failed', id: SAVE_ID, retrying: false }),
        ]);
    });

    it('keeps everything and retries later while the user is still a member', async () => {
        forbidden([ORG_A]);
        await seedDraft();

        expect(await runSync({ manual: true })).toMatchObject({ remaining: 1, state: 'error' });

        expect(await db.drafts.get(UUID)).toMatchObject({ data: { customer: 'ACME' } });
        const op = await db.outbox.get(SAVE_ID);
        expect(op).toMatchObject({ state: 'pending', attempts: 1 });
        expect(op?.lastError).toMatchObject({ status: 403, code: 'organization_forbidden' });
        expect(op?.nextAttemptAt).toBeGreaterThan(Date.now());
    });
});

describe('retired versions (manifest retired_version_ids)', () => {
    async function retire(versionId: number): Promise<void> {
        await db.formVersions.put({
            ...makeVersion(versionId, 3),
            retired: true,
            orgId: 1,
            cachedAt: 1,
        });
    }

    it('blocks a draft before any request and answers a waiting submit', async () => {
        const backend = setup();
        await retire(7);
        await seedDraft();

        const outcome = await enqueueAndRun(
            { id: opId('submit', SCOPE.userId, UUID), submissionUuid: UUID },
            () => enqueueSubmit(SCOPE, UUID, '2026-10-17T10:00:00.000Z'),
        );

        expect(outcome).toMatchObject({
            status: 'failed',
            retrying: false,
            error: { status: 409, code: 'version_retired' },
        });
        expect(backend.state.log).toEqual([]);
        expect(await db.drafts.get(UUID)).toMatchObject({
            sync: 'blocked',
            blockedBy: 'version_retired',
        });
    });

    it('still sends a submitted submission whose version was retired since', async () => {
        const backend = setup();
        await retire(7);
        const submitted = makeSubmission({ uuid: UUID, status: 'submitted', can_edit: false });
        backend.state.submissions.set(UUID, submitted);
        await db.drafts.put(
            draftRow({ uuid: UUID, server: submitted, serverRevision: 1, syncedVersion: 1 }),
        );
        await enqueueSend(SCOPE, UUID, { email: 'kunde@example.test', name: 'Kunde' });

        await runSync({ manual: true });

        expect(summarize(backend.state.log)).toEqual(['SEND']);
        expect(await db.outbox.count()).toBe(0);
    });
});

describe('attachments removed before their upload', () => {
    it('drops the upload op, so the draft update and the submit go out', async () => {
        const backend = setup();
        await seedDraft();
        await db.attachments.put(attachmentRow({ uuid: PHOTO, submissionUuid: UUID }));
        await enqueueAttachmentUpload(SCOPE, UUID, PHOTO);
        const uploadId = opId('attachment.upload', SCOPE.userId, UUID, PHOTO);
        const upload = await db.outbox.get(uploadId);
        // It backs off after failed attempts (blocked ones are dropped the same way).
        await db.outbox.put({ ...upload!, attempts: 3, nextAttemptAt: Date.now() + 60_000 });
        expect(await removeLocalAttachment(SCOPE, PHOTO)).toBe(true);
        await enqueueSubmit(SCOPE, UUID, '2026-10-17T10:00:00.000Z');

        expect(await runSync()).toMatchObject({ remaining: 0, blocked: 0 });

        expect(summarize(backend.state.log)).toEqual(['PUT create', 'SUBMIT']);
        expect(await db.outbox.get(uploadId)).toBeUndefined();
        expect(backend.state.submissions.get(UUID)?.status).toBe('submitted');
    });
});

describe('rows a killed tab left at "syncing"', () => {
    it('queues them again when a run starts (also offline), for the signed-in user only', async () => {
        Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => false });
        await db.drafts.bulkPut([
            draftRow({ uuid: 'mine', sync: 'syncing' }),
            draftRow({ uuid: 'mine-paused', sync: 'conflict' }),
            draftRow({ uuid: 'theirs', userId: 8, sync: 'syncing' }),
        ]);

        expect(await runSync()).toMatchObject({ ran: true, state: 'offline' });

        expect((await db.drafts.get('mine'))?.sync).toBe('queued');
        expect((await db.drafts.get('mine-paused'))?.sync).toBe('conflict');
        expect((await db.drafts.get('theirs'))?.sync).toBe('syncing');
    });
});
