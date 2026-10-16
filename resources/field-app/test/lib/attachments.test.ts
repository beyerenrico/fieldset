import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as endpoints from '../../src/lib/api/endpoints.ts';
import { ApiError } from '../../src/lib/api/errors.ts';
import type { AttachmentRecord } from '../../src/lib/api/types.ts';
import {
    AttachmentUploadError,
    createApiAttachmentAdapter,
} from '../../src/lib/attachments/api-adapter.ts';
import { UUID_V7 } from '../../src/lib/ids.ts';

const request = {
    file: new Blob(['x'], { type: 'image/jpeg' }),
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
const createObjectURL = vi.fn(() => `blob:url-${String(++counter)}`);
const revokeObjectURL = vi.fn<(url: string) => void>();

beforeEach(() => {
    counter = 0;
    // jsdom has no object URLs; each test file runs in its own environment.
    Object.assign(URL, { createObjectURL, revokeObjectURL });
});
afterEach(() => {
    vi.restoreAllMocks();
    createObjectURL.mockClear();
    revokeObjectURL.mockClear();
});

function uploadOk() {
    return vi
        .spyOn(endpoints, 'uploadAttachment')
        .mockImplementation((_uuid, input) =>
            Promise.resolve({ ...record(input.attachmentUuid), kind: input.kind }),
        );
}

describe('API attachment adapter: uploads', () => {
    it('ensures the draft exists, uploads with a v7 uuid and returns the local blob URL', async () => {
        const order: string[] = [];
        const upload = uploadOk().mockImplementationOnce((_uuid, input) => {
            order.push('upload');
            return Promise.resolve(record(input.attachmentUuid));
        });
        const adapter = createApiAttachmentAdapter({
            submissionUuid: 'sub-1',
            initial: [],
            ensureSaved: () => {
                order.push('save');
                return Promise.resolve();
            },
        });
        const info = await adapter.upload(request);
        expect(order).toEqual(['save', 'upload']);
        expect(upload.mock.calls[0]?.[0]).toBe('sub-1');
        expect(info.id).toMatch(UUID_V7);
        expect(info).toEqual({
            id: info.id,
            mime: 'image/jpeg',
            size: 9,
            fileName: 'photo.jpg',
            url: 'blob:url-1',
        });
        expect(createObjectURL).toHaveBeenCalledWith(request.file);
        expect(info.url).not.toContain('/api/');
        expect(adapter.get(info.id)?.url).toBe('blob:url-1');
    });

    it('registers every upload with the tracker while it runs', async () => {
        uploadOk();
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

    it('rejects with a typed, localized error for 422 upload errors', async () => {
        vi.spyOn(endpoints, 'uploadAttachment').mockRejectedValue(
            new ApiError(422, 'file_too_large', 'Too large'),
        );
        const adapter = createApiAttachmentAdapter({
            submissionUuid: 's',
            initial: [],
            ensureSaved: () => Promise.resolve(),
        });
        await expect(adapter.upload(request)).rejects.toMatchObject({
            code: 'file_too_large',
            message: 'Too large',
        });
        adapter.setHandlers({
            ensureSaved: () => Promise.resolve(),
            describeError: (code) => `text:${code}`,
        });
        const error = await adapter.upload(request).catch((e: unknown) => e);
        expect(error).toBeInstanceOf(AttachmentUploadError);
        expect(error).toMatchObject({ code: 'file_too_large', message: 'text:file_too_large' });
    });

    it('passes other errors through and fails when the draft cannot be saved', async () => {
        vi.spyOn(endpoints, 'uploadAttachment').mockRejectedValue(
            new ApiError(500, 'http_500', 'boom'),
        );
        const adapter = createApiAttachmentAdapter({
            submissionUuid: 's',
            initial: [],
            ensureSaved: () => Promise.resolve(),
        });
        await expect(adapter.upload(request)).rejects.toMatchObject({ code: 'http_500' });
        adapter.setHandlers({ ensureSaved: () => Promise.reject(new Error('offline')) });
        await expect(adapter.upload(request)).rejects.toThrow('offline');
    });
});

describe('API attachment adapter: previews', () => {
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

    it('dispose revokes every object URL and ignores downloads still in flight', async () => {
        uploadOk();
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
        const adapter = createApiAttachmentAdapter({
            submissionUuid: 's',
            initial: [record('a')],
            ensureSaved: () => Promise.resolve(),
        });
        await adapter.loadPreviews();
        const uploaded = await adapter.upload(request);
        adapter.merge([record('b')]);
        const pending = adapter.loadPreviews();
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
        uploadOk();
        vi.spyOn(endpoints, 'downloadAttachment').mockResolvedValue(
            new Blob(['a'], { type: 'image/png' }),
        );
        const adapter = createApiAttachmentAdapter({
            submissionUuid: 's',
            initial: [record('a')],
            ensureSaved: () => Promise.resolve(),
        });
        await adapter.loadPreviews();
        expect(adapter.get('a')?.url).toBeUndefined();
        expect((await adapter.upload(request)).url).toBeUndefined();
        adapter.dispose();
    });
});
