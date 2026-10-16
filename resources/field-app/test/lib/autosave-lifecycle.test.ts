/** Autosave lifecycle: stop/dispose, lost sessions, lost responses, keepalive, settling. */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, DeviceWipedError, NetworkError } from '../../src/lib/api/errors.ts';
import type { Submission } from '../../src/lib/api/types.ts';
import { AutosaveController } from '../../src/lib/autosave/controller.ts';
import type { SaveRequest } from '../../src/lib/autosave/types.ts';
import { makeSubmission } from '../utils/fixtures.ts';

type Save = (request: SaveRequest) => Promise<Submission>;

const saved = (request: SaveRequest, revision: number): Submission =>
    makeSubmission({ uuid: request.uuid, data: request.data, revision });

function controller(
    save: Save,
    revision = 0,
    extra: Partial<ConstructorParameters<typeof AutosaveController>[0]> = {},
) {
    return new AutosaveController({
        uuid: 'u1',
        formVersionId: 7,
        revision,
        data: {},
        transport: { save },
        debounceMs: 1500,
        retryMs: 1000,
        ...extra,
    });
}

const conflict = (server: Submission) =>
    new ApiError(409, 'revision_conflict', 'conflict', { submission: server });

beforeEach(() => {
    vi.useFakeTimers();
});
afterEach(() => {
    vi.useRealTimers();
});

describe('AutosaveController: stop and dispose', () => {
    it('stop() saves once more, then ignores edits and never saves again', async () => {
        const save = vi.fn<Save>((request) => Promise.resolve(saved(request, 2)));
        const autosave = controller(save, 1);
        autosave.start();
        autosave.update({ a: 1 });
        await autosave.stop();
        expect(save).toHaveBeenCalledOnce();
        autosave.update({ a: 2 });
        await autosave.flush({ ensureCreated: true });
        window.dispatchEvent(new Event('online'));
        await vi.advanceTimersByTimeAsync(120_000);
        expect(save).toHaveBeenCalledOnce();
        expect(autosave.data).toEqual({ a: 1 });
    });

    it('does not bring retry timers back after stop()', async () => {
        const save = vi.fn<Save>(() => Promise.reject(new NetworkError('down')));
        const autosave = controller(save, 1);
        autosave.start();
        autosave.update({ a: 1 });
        await vi.advanceTimersByTimeAsync(1500);
        expect(autosave.getSnapshot().state).toBe('offline');
        await autosave.stop();
        const calls = save.mock.calls.length;
        await vi.advanceTimersByTimeAsync(300_000);
        window.dispatchEvent(new Event('online'));
        await vi.advanceTimersByTimeAsync(0);
        expect(save).toHaveBeenCalledTimes(calls);
        expect(autosave.busy()).toBe(false);
    });

    it('dispose() drops pending changes without saving', async () => {
        const save = vi.fn<Save>((request) => Promise.resolve(saved(request, 2)));
        const autosave = controller(save, 1);
        autosave.start();
        autosave.update({ a: 1 });
        autosave.dispose();
        await vi.advanceTimersByTimeAsync(10_000);
        await autosave.stop();
        expect(save).not.toHaveBeenCalled();
    });
});

describe('AutosaveController: lost session', () => {
    it('skips the final save in stop() after an auth loss', async () => {
        const onAuthLost = vi.fn();
        const save = vi.fn<Save>(() => Promise.reject(new DeviceWipedError('wiped')));
        const autosave = controller(save, 1, { onAuthLost });
        autosave.start();
        autosave.update({ a: 1 });
        await vi.advanceTimersByTimeAsync(1500);
        expect(onAuthLost).toHaveBeenCalledOnce();
        expect(autosave.sessionLost).toBe(true);
        await autosave.stop();
        await vi.advanceTimersByTimeAsync(120_000);
        expect(save).toHaveBeenCalledOnce();
        expect(onAuthLost).toHaveBeenCalledOnce();
    });

    it('forgets the lost session after a later successful save', async () => {
        const save = vi
            .fn<Save>()
            .mockRejectedValueOnce(new DeviceWipedError('wiped'))
            .mockImplementation((request) => Promise.resolve(saved(request, 2)));
        const autosave = controller(save, 1);
        autosave.update({ a: 1 });
        await vi.advanceTimersByTimeAsync(1500);
        expect(autosave.sessionLost).toBe(true);
        autosave.update({ a: 2 });
        await vi.advanceTimersByTimeAsync(1500);
        expect(autosave.sessionLost).toBe(false);
        expect(autosave.getSnapshot()).toMatchObject({ state: 'saved', revision: 2 });
    });
});

describe('AutosaveController: lost responses', () => {
    it('adopts the server revision when its copy equals the data that was sent', async () => {
        const server = makeSubmission({ revision: 2, data: { b: { y: 2, x: 1 }, a: 1 } });
        const save = vi
            .fn<Save>()
            .mockRejectedValueOnce(new NetworkError('response lost'))
            .mockRejectedValueOnce(conflict(server))
            .mockImplementation((request) => Promise.resolve(saved(request, request.revision + 1)));
        const onSaved = vi.fn();
        const autosave = controller(save, 1, { onSaved });
        autosave.update({ a: 1, b: { x: 1, y: 2 } });
        await vi.advanceTimersByTimeAsync(1500);
        expect(autosave.getSnapshot().state).toBe('offline');
        await vi.advanceTimersByTimeAsync(1000);
        expect(autosave.getSnapshot()).toMatchObject({
            state: 'saved',
            revision: 2,
            conflict: null,
            error: null,
        });
        expect(onSaved).toHaveBeenCalledWith(server);
        await vi.advanceTimersByTimeAsync(10_000);
        expect(save).toHaveBeenCalledTimes(2);
    });

    it('adopts the revision and saves newer local edits on top of it', async () => {
        const server = makeSubmission({ revision: 1, data: { a: 1 } });
        let reject: (error: unknown) => void = () => undefined;
        const save = vi
            .fn<Save>()
            .mockImplementationOnce(
                () =>
                    new Promise<Submission>((_resolve, fail) => {
                        reject = fail;
                    }),
            )
            .mockImplementation((request) => Promise.resolve(saved(request, request.revision + 1)));
        const autosave = controller(save, 0);
        autosave.update({ a: 1 });
        await vi.advanceTimersByTimeAsync(1500);
        autosave.update({ a: 2 });
        reject(conflict(server));
        await vi.advanceTimersByTimeAsync(0);
        expect(autosave.getSnapshot()).toMatchObject({ state: 'pending', conflict: null });
        await vi.advanceTimersByTimeAsync(1500);
        expect(save).toHaveBeenLastCalledWith({
            uuid: 'u1',
            formVersionId: 7,
            data: { a: 2 },
            revision: 1,
        });
        await vi.advanceTimersByTimeAsync(0);
        expect(autosave.getSnapshot()).toMatchObject({ state: 'saved', revision: 2 });
    });

    it('still reports a real conflict when the server copy differs', async () => {
        const server = makeSubmission({ revision: 4, data: { a: 'other' } });
        const autosave = controller(() => Promise.reject(conflict(server)), 3);
        autosave.update({ a: 'mine' });
        await vi.advanceTimersByTimeAsync(1500);
        expect(autosave.getSnapshot()).toMatchObject({ state: 'conflict', conflict: server });
    });

    it('never opens the conflict panel for an identical server copy reported on submit', () => {
        const autosave = controller(vi.fn<Save>(), 3, { data: { a: [1, { b: null }] } });
        const same = makeSubmission({ revision: 5, data: { a: [1, { b: null }] } });
        expect(autosave.reportConflict(same)).toBe(false);
        expect(autosave.getSnapshot()).toMatchObject({ state: 'saved', revision: 5 });
        const other = makeSubmission({ revision: 6, data: { a: [1] } });
        expect(autosave.reportConflict(other)).toBe(true);
        expect(autosave.getSnapshot()).toMatchObject({ state: 'conflict', conflict: other });
    });
});

describe('AutosaveController: data store and settling', () => {
    it('notifies on every edit, also while saving or blocked', async () => {
        const autosave = controller(() => new Promise<Submission>(() => undefined), 1);
        const listener = vi.fn();
        autosave.subscribe(listener);
        autosave.update({ a: 1 });
        await vi.advanceTimersByTimeAsync(1500);
        listener.mockClear();
        autosave.update({ a: 2 });
        expect(listener).toHaveBeenCalled();
        expect(autosave.data).toEqual({ a: 2 });
    });

    it('settles once a retried save went through', async () => {
        let fail = true;
        const save = vi.fn<Save>((request) =>
            fail ? Promise.reject(new NetworkError('down')) : Promise.resolve(saved(request, 2)),
        );
        const autosave = controller(save, 1);
        await autosave.settled();
        autosave.update({ a: 1 });
        await vi.advanceTimersByTimeAsync(1500);
        const done = vi.fn();
        void autosave.settled().then(done);
        await vi.advanceTimersByTimeAsync(0);
        expect(done).not.toHaveBeenCalled();
        fail = false;
        await vi.advanceTimersByTimeAsync(1000);
        expect(done).toHaveBeenCalledOnce();
        expect(autosave.getSnapshot()).toMatchObject({ state: 'saved', revision: 2 });
    });

    it('sends the save on page hide with keepalive', async () => {
        const save = vi.fn<Save>((request) => Promise.resolve(saved(request, 2)));
        const autosave = controller(save, 1);
        autosave.start();
        autosave.update({ a: 1 });
        Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
        document.dispatchEvent(new Event('visibilitychange'));
        await vi.advanceTimersByTimeAsync(0);
        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            value: 'visible',
        });
        expect(save).toHaveBeenCalledWith(expect.objectContaining({ keepalive: true }));
        autosave.dispose();
    });
});
