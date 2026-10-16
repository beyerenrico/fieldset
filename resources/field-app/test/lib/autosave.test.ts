import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, NetworkError, ReauthRequiredError } from '../../src/lib/api/errors.ts';
import type { Submission } from '../../src/lib/api/types.ts';
import { AutosaveController } from '../../src/lib/autosave/controller.ts';
import type { SaveRequest, SaveTransport } from '../../src/lib/autosave/types.ts';
import { makeSubmission } from '../utils/fixtures.ts';

interface Deferred {
    request: SaveRequest;
    resolve: (submission: Submission) => void;
    reject: (error: unknown) => void;
}

function manualTransport() {
    const calls: Deferred[] = [];
    const transport: SaveTransport = {
        save: (request) =>
            new Promise<Submission>((resolve, reject) => {
                calls.push({ request, resolve, reject });
            }),
    };
    return { calls, transport };
}

const saved = (request: SaveRequest, revision: number): Submission =>
    makeSubmission({ uuid: request.uuid, data: request.data, revision });

function controller(
    transport: SaveTransport,
    revision = 0,
    extra: Partial<ConstructorParameters<typeof AutosaveController>[0]> = {},
) {
    return new AutosaveController({
        uuid: 'u1',
        formVersionId: 7,
        revision,
        data: {},
        transport,
        debounceMs: 1500,
        retryMs: 1000,
        ...extra,
    });
}

beforeEach(() => {
    vi.useFakeTimers();
});
afterEach(() => {
    vi.useRealTimers();
});

describe('AutosaveController', () => {
    it('debounces edits into one save and tracks the revision', async () => {
        const { calls, transport } = manualTransport();
        const autosave = controller(transport);
        autosave.update({ a: 1 });
        autosave.update({ a: 2 });
        expect(autosave.getSnapshot().state).toBe('pending');
        await vi.advanceTimersByTimeAsync(1499);
        expect(calls).toHaveLength(0);
        await vi.advanceTimersByTimeAsync(1);
        expect(calls).toHaveLength(1);
        expect(calls[0]?.request).toEqual({
            uuid: 'u1',
            formVersionId: 7,
            data: { a: 2 },
            revision: 0,
        });
        expect(autosave.getSnapshot().state).toBe('saving');
        calls[0]?.resolve(saved(calls[0].request, 1));
        await vi.runOnlyPendingTimersAsync();
        expect(autosave.getSnapshot()).toMatchObject({ state: 'saved', revision: 1 });
        expect(autosave.getSnapshot().savedAt).not.toBeNull();
    });

    it('never runs two saves at once and sends the latest data afterwards', async () => {
        const { calls, transport } = manualTransport();
        const autosave = controller(transport, 1);
        autosave.update({ a: 1 });
        await vi.advanceTimersByTimeAsync(1500);
        autosave.update({ a: 2 });
        autosave.update({ a: 3 });
        await vi.advanceTimersByTimeAsync(5000);
        expect(calls).toHaveLength(1);
        calls[0]?.resolve(saved(calls[0].request, 2));
        await vi.advanceTimersByTimeAsync(10);
        expect(calls).toHaveLength(2);
        expect(calls[1]?.request).toMatchObject({ data: { a: 3 }, revision: 2 });
        calls[1]?.resolve(saved(calls[1].request, 3));
        await vi.advanceTimersByTimeAsync(10);
        expect(autosave.getSnapshot()).toMatchObject({ state: 'saved', revision: 3 });
    });

    it('flush saves immediately; ensureCreated forces the first PUT', async () => {
        const transport: SaveTransport = {
            save: (request) => Promise.resolve(saved(request, request.revision + 1)),
        };
        const autosave = controller(transport);
        const snapshot = await autosave.flush({ ensureCreated: true });
        expect(snapshot).toMatchObject({ state: 'saved', revision: 1 });
        autosave.update({ b: true });
        expect((await autosave.flush()).revision).toBe(2);
        expect((await autosave.flush()).revision).toBe(2);
        expect(autosave.data).toEqual({ b: true });
    });

    it('goes offline on network errors and retries with backoff and on online', async () => {
        let fail = true;
        const save = vi.fn((request: SaveRequest) =>
            fail ? Promise.reject(new NetworkError('down')) : Promise.resolve(saved(request, 1)),
        );
        const autosave = controller({ save });
        autosave.start();
        autosave.update({ a: 1 });
        await vi.advanceTimersByTimeAsync(1500);
        expect(autosave.getSnapshot().state).toBe('offline');
        await vi.advanceTimersByTimeAsync(1000);
        expect(save).toHaveBeenCalledTimes(2);
        fail = false;
        window.dispatchEvent(new Event('online'));
        await vi.advanceTimersByTimeAsync(0);
        expect(autosave.getSnapshot()).toMatchObject({ state: 'saved', revision: 1 });
        autosave.stop();
    });

    it('enters conflict on revision_conflict and resolves with "keep mine"', async () => {
        const server = makeSubmission({ revision: 5, data: { a: 'server' } });
        const save = vi
            .fn<(request: SaveRequest) => Promise<Submission>>()
            .mockRejectedValueOnce(
                new ApiError(409, 'revision_conflict', 'conflict', { submission: server }),
            )
            .mockImplementation((request) => Promise.resolve(saved(request, request.revision + 1)));
        const autosave = controller({ save }, 3);
        autosave.update({ a: 'mine' });
        await vi.advanceTimersByTimeAsync(1500);
        expect(autosave.getSnapshot()).toMatchObject({ state: 'conflict', conflict: server });
        autosave.update({ a: 'mine again' });
        await vi.advanceTimersByTimeAsync(5000);
        expect(save).toHaveBeenCalledTimes(1);
        const data = autosave.resolveConflict('mine');
        expect(data).toEqual({ a: 'mine again' });
        await vi.advanceTimersByTimeAsync(0);
        expect(save).toHaveBeenLastCalledWith({
            uuid: 'u1',
            formVersionId: 7,
            data: { a: 'mine again' },
            revision: 5,
        });
        expect(autosave.getSnapshot()).toMatchObject({
            state: 'saved',
            revision: 6,
            conflict: null,
        });
    });

    it('resolves a conflict with the server version', () => {
        const server = makeSubmission({ revision: 9, data: { a: 'server' } });
        const autosave = controller({ save: vi.fn() }, 3);
        expect(autosave.resolveConflict('server')).toEqual({});
        autosave.reportConflict(server);
        const data = autosave.resolveConflict('server');
        expect(data).toEqual({ a: 'server' });
        expect(autosave.getSnapshot()).toMatchObject({
            state: 'saved',
            revision: 9,
            conflict: null,
        });
        expect(autosave.data).toEqual({ a: 'server' });
    });

    it('locks on not_editable and stops saving', async () => {
        const save = vi.fn(() => Promise.reject(new ApiError(409, 'not_editable', 'locked')));
        const autosave = controller({ save }, 2);
        autosave.update({ a: 1 });
        await vi.advanceTimersByTimeAsync(1500);
        expect(autosave.getSnapshot().state).toBe('locked');
        autosave.update({ a: 2 });
        expect((await autosave.flush()).state).toBe('locked');
        expect(save).toHaveBeenCalledTimes(1);
    });

    it('reports auth loss and retries server errors', async () => {
        const onAuthLost = vi.fn();
        const save = vi
            .fn<(request: SaveRequest) => Promise<Submission>>()
            .mockRejectedValueOnce(new ReauthRequiredError('gone'))
            .mockRejectedValueOnce(new ApiError(503, 'http_503', 'down'))
            .mockImplementation((request) => Promise.resolve(saved(request, 1)));
        const autosave = controller({ save }, 0, { onAuthLost });
        autosave.update({ a: 1 });
        await vi.advanceTimersByTimeAsync(1500);
        expect(autosave.getSnapshot().state).toBe('error');
        expect(onAuthLost).toHaveBeenCalledOnce();
        autosave.update({ a: 2 });
        await vi.advanceTimersByTimeAsync(1500);
        expect(autosave.getSnapshot().state).toBe('error');
        await vi.advanceTimersByTimeAsync(1000);
        expect(autosave.getSnapshot()).toMatchObject({ state: 'saved', revision: 1 });
    });

    it('flushes when the page is hidden and notifies subscribers', async () => {
        const save = vi.fn((request: SaveRequest) => Promise.resolve(saved(request, 1)));
        const autosave = controller({ save });
        const listener = vi.fn();
        const unsubscribe = autosave.subscribe(listener);
        autosave.start();
        autosave.update({ a: 1 });
        Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
        document.dispatchEvent(new Event('visibilitychange'));
        await vi.advanceTimersByTimeAsync(0);
        expect(save).toHaveBeenCalledOnce();
        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            value: 'visible',
        });
        expect(listener).toHaveBeenCalled();
        unsubscribe();
        autosave.acceptServer(makeSubmission({ revision: 4 }));
        expect(autosave.getSnapshot().revision).toBe(4);
        autosave.stop();
    });
});
