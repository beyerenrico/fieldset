import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as endpoints from '../../src/lib/api/endpoints.ts';
import { ApiError } from '../../src/lib/api/errors.ts';
import type { AttachmentRecord } from '../../src/lib/api/types.ts';
import {
    AttachmentUploadError,
    createApiAttachmentAdapter,
} from '../../src/lib/attachments/api-adapter.ts';
import {
    pruneUploadedAttachments,
    startAttachmentPruning,
} from '../../src/lib/attachments/local-store.ts';
import { db } from '../../src/lib/db/db.ts';
import type { DraftRow } from '../../src/lib/db/schema.ts';
import { setCurrentUserId } from '../../src/lib/db/scope.ts';
import { UUID_V7 } from '../../src/lib/ids.ts';
import { enqueueAttachmentUpload } from '../../src/lib/outbox/outbox.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { requestSync, resetSyncTriggersForTests } from '../../src/lib/sync/triggers.ts';
import { attachmentRow, readBlob } from '../utils/b-attachments.ts';
import { makeSubmission } from '../utils/fixtures.ts';
import { setupTestServer } from '../utils/server.ts';

vi.mock('../../src/lib/outbox/outbox.ts', async (importOriginal) => ({
    ...(await importOriginal<typeof import('../../src/lib/outbox/outbox.ts')>()),
    enqueueAttachmentUpload: vi.fn(() => Promise.resolve()),
}));

setupTestServer();

const SCOPE = { orgId: 1, userId: 7 };
const enqueue = vi.mocked(enqueueAttachmentUpload);

const request = {
    file: new Blob(['xyz'], { type: 'image/jpeg' }),
    fileName: 'photo.jpg',
    kind: 'photo' as const,
    elementKey: 'photos',
    pointer: '/photos',
};

const record = (uuid: string, mime = 'image/jpeg'): AttachmentRecord => ({
    uuid,
    kind: 'photo',
    element_key: 'photos',
    mime,
    size: 9,
    url: `/api/v1/submissions/s/attachments/${uuid}`,
});

let counter = 0;
const createObjectURL = vi.fn<(blob: Blob) => string>(() => `blob:url-${String(++counter)}`);
const revokeObjectURL = vi.fn<(url: string) => void>();

beforeEach(() => {
    counter = 0;
    // jsdom has no object URLs; each test file runs in its own environment.
    Object.assign(URL, { createObjectURL, revokeObjectURL });
    setOrganizationId(SCOPE.orgId);
    setCurrentUserId(SCOPE.userId);
});
afterEach(() => {
    vi.restoreAllMocks();
    createObjectURL.mockClear();
    revokeObjectURL.mockClear();
    enqueue.mockClear();
    setCurrentUserId(null);
});

const adapterFor = (submissionUuid = 'sub-1', initial: readonly AttachmentRecord[] = []) =>
    createApiAttachmentAdapter({ submissionUuid, initial, ensureSaved: () => Promise.resolve() });

describe('attachment adapter: local-first uploads', () => {
    it('stores a pending row, queues the upload and returns the local blob URL', async () => {
        const network = vi.spyOn(endpoints, 'uploadAttachment');
        const ensureSaved = vi.fn(() => Promise.resolve());
        const adapter = createApiAttachmentAdapter({
            submissionUuid: 'sub-1',
            initial: [],
            ensureSaved,
        });
        const info = await adapter.upload(request);

        expect(info.id).toMatch(UUID_V7);
        expect(info).toEqual({
            id: info.id,
            mime: 'image/jpeg',
            size: 3,
            fileName: 'photo.jpg',
            url: 'blob:url-1',
        });
        const row = await db.attachments.get(info.id);
        expect(row).toMatchObject({
            ...SCOPE,
            uuid: info.id,
            submissionUuid: 'sub-1',
            kind: 'photo',
            elementKey: 'photos',
            fileName: 'photo.jpg',
            mime: 'image/jpeg',
            size: 3,
            state: 'pending',
            record: null,
        });
        expect(new TextDecoder().decode(row?.bytes ?? new ArrayBuffer(0))).toBe('xyz');
        expect(enqueue).toHaveBeenCalledWith(SCOPE, 'sub-1', info.id);
        // The outbox sends the draft before its attachments: no server round trip here.
        expect(network).not.toHaveBeenCalled();
        expect(ensureSaved).not.toHaveBeenCalled();
        expect(createObjectURL).toHaveBeenCalledWith(request.file);
        expect(adapter.get(info.id)?.url).toBe('blob:url-1');
    });

    it('registers every upload with the tracker while it runs', async () => {
        const tracked: Promise<unknown>[] = [];
        const adapter = createApiAttachmentAdapter({
            submissionUuid: 's',
            initial: [],
            ensureSaved: () => Promise.resolve(),
            track: (work) => {
                tracked.push(work);
                return work;
            },
        });
        const pending = adapter.upload(request);
        expect(tracked).toEqual([pending]);
        await expect(pending).resolves.toMatchObject({ fileName: 'photo.jpg' });
    });

    it('removes the row again and rethrows when the upload cannot be queued', async () => {
        enqueue.mockRejectedValueOnce(new Error('queue down'));
        await expect(adapterFor().upload(request)).rejects.toThrow('queue down');
        expect(await db.attachments.count()).toBe(0);
    });

    it('reports a full device with a typed, localized error', async () => {
        const quota = Object.assign(new Error('quota'), { name: 'QuotaExceededError' });
        vi.spyOn(db.attachments, 'put').mockRejectedValue(quota);
        const adapter = adapterFor();
        adapter.setHandlers({
            ensureSaved: () => Promise.resolve(),
            describeError: (code) => `text:${code}`,
        });
        const error = await adapter.upload(request).catch((e: unknown) => e);
        expect(error).toBeInstanceOf(AttachmentUploadError);
        expect(error).toMatchObject({ code: 'storage_full', message: 'text:storage_full' });
        expect(enqueue).not.toHaveBeenCalled();
    });

    it('refuses to keep files without a signed-in user', async () => {
        setCurrentUserId(null);
        await expect(adapterFor().upload(request)).rejects.toMatchObject({
            code: 'not_signed_in',
        });
    });

    it('removes a file that was not uploaded yet, but keeps uploaded ones', async () => {
        const adapter = adapterFor();
        const info = await adapter.upload(request);
        await adapter.remove?.(info.id, request);
        expect(await db.attachments.get(info.id)).toBeUndefined();
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:url-1');
        expect(adapter.get(info.id)).toBeUndefined();

        await db.attachments.put(attachmentRow({ uuid: 'up-1', state: 'uploaded' }));
        await adapter.remove?.('up-1', request);
        expect(await db.attachments.get('up-1')).toBeDefined();
    });
});

describe('attachment adapter: previews', () => {
    it('downloads only image previews, bounded, ignores failures and notifies', async () => {
        let active = 0;
        let peak = 0;
        const download = vi
            .spyOn(endpoints, 'downloadAttachment')
            .mockImplementation(async (_uuid, id) => {
                active++;
                peak = Math.max(peak, active);
                await new Promise((resolve) => setTimeout(resolve, 5));
                active--;
                if (id === 'broken') {
                    throw new ApiError(404, 'not_found', 'gone');
                }
                return new Blob([id], { type: 'image/jpeg' });
            });
        const adapter = createApiAttachmentAdapter({
            submissionUuid: 'sub-1',
            initial: [record('a'), record('b'), record('doc', 'application/pdf')],
            ensureSaved: () => Promise.resolve(),
            concurrency: 2,
        });
        adapter.merge([record('a'), record('c'), record('broken')]);
        const listener = vi.fn();
        const unsubscribe = adapter.subscribe(listener);
        expect(adapter.get('a')).toEqual({ id: 'a', mime: 'image/jpeg', size: 9 });
        await adapter.loadPreviews();
        expect(download.mock.calls.map((call) => call[1]).sort()).toEqual([
            'a',
            'b',
            'broken',
            'c',
        ]);
        expect(download.mock.calls.every((call) => call[0] === 'sub-1')).toBe(true);
        expect(peak).toBe(2);
        expect(listener).toHaveBeenCalledTimes(3);
        expect(adapter.getVersion()).toBe(3);
        expect(adapter.get('a')?.url).toMatch(/^blob:url-/);
        expect(adapter.get('broken')?.url).toBeUndefined();
        expect(adapter.get('doc')?.url).toBeUndefined();
        expect(adapter.get('unknown')).toBeUndefined();
        await adapter.loadPreviews();
        expect(download).toHaveBeenCalledTimes(5);
        unsubscribe();
    });

    it('shows local attachments (e.g. after a reload) from their bytes without a download', async () => {
        const download = vi.spyOn(endpoints, 'downloadAttachment');
        await db.attachments.bulkPut([
            attachmentRow({ uuid: 'local-1', fileName: 'site.jpg' }),
            attachmentRow({ uuid: 'other-user', userId: 99 }),
            attachmentRow({ uuid: 'other-draft', submissionUuid: 'sub-2' }),
        ]);
        const adapter = adapterFor();
        await adapter.loadPreviews();
        expect(adapter.get('local-1')).toEqual({
            id: 'local-1',
            mime: 'image/jpeg',
            size: 3,
            fileName: 'site.jpg',
            url: 'blob:url-1',
        });
        expect(adapter.get('other-user')).toBeUndefined();
        expect(adapter.get('other-draft')).toBeUndefined();
        expect(await readBlob(createObjectURL.mock.calls[0]![0])).toBe('abc');
        expect(download).not.toHaveBeenCalled();
    });

    it('falls back to a server download once the bytes were pruned', async () => {
        const download = vi
            .spyOn(endpoints, 'downloadAttachment')
            .mockResolvedValue(new Blob(['srv'], { type: 'image/jpeg' }));
        await db.attachments.put(attachmentRow({ uuid: 'a', state: 'uploaded', bytes: null }));
        const adapter = adapterFor('sub-1', [record('a')]);
        await adapter.loadPreviews();
        expect(download).toHaveBeenCalledWith('sub-1', 'a');
        expect(adapter.get('a')?.url).toBe('blob:url-1');
    });

    it('dispose revokes every object URL and ignores downloads still in flight', async () => {
        let release: (blob: Blob) => void = () => undefined;
        vi.spyOn(endpoints, 'downloadAttachment')
            .mockResolvedValueOnce(new Blob(['a'], { type: 'image/png' }))
            .mockImplementationOnce(
                () =>
                    new Promise<Blob>((resolve) => {
                        release = resolve;
                    }),
            )
            .mockResolvedValue(new Blob(['b'], { type: 'image/png' }));
        const adapter = adapterFor('s', [record('a')]);
        await adapter.loadPreviews();
        const uploaded = await adapter.upload(request);
        adapter.merge([record('b')]);
        const pending = adapter.loadPreviews();
        await vi.waitFor(() => {
            expect(endpoints.downloadAttachment).toHaveBeenCalledTimes(2);
        });
        adapter.dispose();
        expect(revokeObjectURL.mock.calls.map((call) => call[0]).sort()).toEqual([
            'blob:url-1',
            'blob:url-2',
        ]);
        expect(adapter.get('a')?.url).toBeUndefined();
        expect(adapter.get(uploaded.id)?.url).toBeUndefined();
        release(new Blob(['late'], { type: 'image/png' }));
        await pending;
        expect(adapter.get('b')?.url).toBeUndefined();
        await adapter.loadPreviews();
        expect(adapter.get('b')?.url).toMatch(/^blob:url-/);
    });

    it('works without object URL support', async () => {
        Object.assign(URL, { createObjectURL: undefined, revokeObjectURL: undefined });
        vi.spyOn(endpoints, 'downloadAttachment').mockResolvedValue(
            new Blob(['a'], { type: 'image/png' }),
        );
        const adapter = adapterFor('s', [record('a')]);
        await adapter.loadPreviews();
        expect(adapter.get('a')?.url).toBeUndefined();
        expect((await adapter.upload(request)).url).toBeUndefined();
        adapter.dispose();
    });
});

function draftRow(uuid: string, canEdit: boolean): DraftRow {
    return {
        ...SCOPE,
        uuid,
        formId: 3,
        formVersionId: 7,
        data: {},
        localVersion: 1,
        syncedVersion: 1,
        serverRevision: 1,
        server: makeSubmission({
            uuid,
            can_edit: canEdit,
            status: canEdit ? 'draft' : 'submitted',
        }),
        conflict: null,
        sync: 'synced',
        blockedBy: null,
        submitErrors: [],
        createdAt: 1,
        updatedAt: 1,
    };
}

describe('pruning uploaded bytes', () => {
    beforeEach(async () => {
        await db.drafts.bulkPut([draftRow('editable', true), draftRow('submitted', false)]);
        await db.attachments.bulkPut([
            attachmentRow({ uuid: 'kept-editable', submissionUuid: 'editable', state: 'uploaded' }),
            attachmentRow({ uuid: 'pruned', submissionUuid: 'submitted', state: 'uploaded' }),
            attachmentRow({ uuid: 'pending', submissionUuid: 'submitted' }),
            attachmentRow({ uuid: 'failed', submissionUuid: 'submitted', state: 'failed' }),
            attachmentRow({ uuid: 'no-draft', submissionUuid: 'gone', state: 'uploaded' }),
            attachmentRow({
                uuid: 'other-user',
                submissionUuid: 'submitted',
                state: 'uploaded',
                userId: 99,
            }),
        ]);
    });
    afterEach(() => {
        resetSyncTriggersForTests();
    });

    const withBytes = async (): Promise<string[]> =>
        (await db.attachments.toArray())
            .filter((row) => row.bytes !== null)
            .map((row) => row.uuid)
            .sort();

    it('drops bytes only of uploaded rows whose draft is submitted or gone', async () => {
        expect(await pruneUploadedAttachments(SCOPE, 'submitted')).toBe(1);
        expect(await pruneUploadedAttachments(SCOPE)).toBe(1);
        expect(await withBytes()).toEqual(['failed', 'kept-editable', 'other-user', 'pending']);
        expect(await db.attachments.get('pruned')).toMatchObject({ state: 'uploaded' });
    });

    it('prunes on sync requests, not on enqueues', async () => {
        const stop = startAttachmentPruning();
        requestSync('enqueue');
        await new Promise((resolve) => setTimeout(resolve, 20));
        expect(await withBytes()).toHaveLength(6);
        requestSync('online');
        await vi.waitFor(async () => {
            expect(await withBytes()).toHaveLength(4);
        });
        stop();
    });
});
