/** AutosaveController (local-first, ADR 0035): debounce, maxWait, one write in flight, retries. */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AutosaveController } from '../../src/lib/autosave/controller.ts';
import { StorageFullError } from '../../src/lib/autosave/errors.ts';
import type { SaveReceipt, SaveRequest, SaveTransport } from '../../src/lib/autosave/types.ts';

interface Deferred {
    request: SaveRequest;
    resolve: (receipt: SaveReceipt) => void;
    reject: (error: unknown) => void;
}

function manualTransport() {
    const calls: Deferred[] = [];
    const transport: SaveTransport = {
        save: (request) =>
            new Promise<SaveReceipt>((resolve, reject) => {
                calls.push({ request, resolve, reject });
            }),
    };
    return { calls, transport };
}

/** Writes succeed at once and count up like the DraftRow's localVersion. */
function countingTransport() {
    let version = 0;
    const save = vi.fn<(request: SaveRequest) => Promise<SaveReceipt>>(() => {
        version += 1;
        return Promise.resolve({ localVersion: version });
    });
    return { save, transport: { save } satisfies SaveTransport };
}

function controller(
    transport: SaveTransport,
    extra: Partial<ConstructorParameters<typeof AutosaveController>[0]> = {},
) {
    return new AutosaveController({
        uuid: 'u1',
        formVersionId: 7,
        data: {},
        transport,
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

describe('AutosaveController (local writes)', () => {
    it('debounces edits by 300 ms into one write; "saved" means saved on this device', async () => {
        const { calls, transport } = manualTransport();
        const autosave = controller(transport);
        autosave.update({ a: 1 });
        autosave.update({ a: 2 });
        expect(autosave.getSnapshot().state).toBe('pending');
        await vi.advanceTimersByTimeAsync(299);
        expect(calls).toHaveLength(0);
        await vi.advanceTimersByTimeAsync(1);
        expect(calls).toHaveLength(1);
        expect(calls[0]?.request).toEqual({ uuid: 'u1', formVersionId: 7, data: { a: 2 } });
        expect(autosave.getSnapshot().state).toBe('saving');
        calls[0]?.resolve({ localVersion: 1 });
        await vi.runOnlyPendingTimersAsync();
        expect(autosave.getSnapshot()).toMatchObject({ state: 'saved', localVersion: 1 });
        expect(autosave.getSnapshot().savedAt).not.toBeNull();
    });

    it('writes at the latest 2 s after the first edit while edits keep coming', async () => {
        const { save, transport } = countingTransport();
        const autosave = controller(transport);
        for (let step = 0; step < 9; step++) {
            autosave.update({ step });
            await vi.advanceTimersByTimeAsync(250);
        }
        expect(save).toHaveBeenCalledTimes(1);
        expect(save.mock.calls[0]?.[0].data).toEqual({ step: 7 });
        await vi.advanceTimersByTimeAsync(300);
        expect(save).toHaveBeenCalledTimes(2);
        expect(save.mock.calls[1]?.[0].data).toEqual({ step: 8 });
    });

    it('never runs two writes at once and writes the latest data afterwards', async () => {
        const { calls, transport } = manualTransport();
        const autosave = controller(transport);
        autosave.update({ a: 1 });
        await vi.advanceTimersByTimeAsync(300);
        autosave.update({ a: 2 });
        autosave.update({ a: 3 });
        await vi.advanceTimersByTimeAsync(5000);
        expect(calls).toHaveLength(1);
        calls[0]?.resolve({ localVersion: 1 });
        await vi.advanceTimersByTimeAsync(10);
        expect(calls).toHaveLength(2);
        expect(calls[1]?.request.data).toEqual({ a: 3 });
        calls[1]?.resolve({ localVersion: 2 });
        await vi.advanceTimersByTimeAsync(10);
        expect(autosave.getSnapshot()).toMatchObject({ state: 'saved', localVersion: 2 });
    });

    it('flush writes at once; ensureCreated writes the row once before the first edit', async () => {
        const { save, transport } = countingTransport();
        const autosave = controller(transport);
        const snapshot = await autosave.flush({ ensureCreated: true });
        expect(snapshot).toMatchObject({ state: 'saved', localVersion: 1 });
        expect(save.mock.calls[0]?.[0]).toMatchObject({ data: {}, ensureOnly: true });
        expect((await autosave.flush({ ensureCreated: true })).localVersion).toBe(1);
        autosave.update({ b: true });
        expect((await autosave.flush()).localVersion).toBe(2);
        expect((await autosave.flush()).localVersion).toBe(2);
        expect(save).toHaveBeenCalledTimes(2);
        expect(autosave.data).toEqual({ b: true });
    });

    it('does not force a row for a draft already on this device', async () => {
        const { save, transport } = countingTransport();
        const autosave = controller(transport, { localVersion: 4 });
        await autosave.flush({ ensureCreated: true });
        expect(save).not.toHaveBeenCalled();
    });

    it('keeps the data after a failed local write and retries with backoff', async () => {
        let failing = true;
        const save = vi.fn(() =>
            failing
                ? Promise.reject(new Error('QuotaExceededError'))
                : Promise.resolve({ localVersion: 1 }),
        );
        const autosave = controller({ save });
        autosave.update({ a: 1 });
        await vi.advanceTimersByTimeAsync(300);
        expect(autosave.getSnapshot().state).toBe('error');
        await vi.advanceTimersByTimeAsync(1000);
        expect(save).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(1999);
        expect(save).toHaveBeenCalledTimes(2);
        failing = false;
        await vi.advanceTimersByTimeAsync(1);
        expect(save).toHaveBeenCalledTimes(3);
        expect(autosave.getSnapshot()).toMatchObject({
            state: 'saved',
            localVersion: 1,
            error: null,
        });
    });

    it('keeps one error object and stays quiet while retries fail the same way', async () => {
        const save = vi.fn(() => Promise.reject(new StorageFullError()));
        const autosave = controller({ save });
        const listener = vi.fn();
        autosave.subscribe(listener);
        autosave.update({ a: 1 });
        await vi.advanceTimersByTimeAsync(300);
        const first = autosave.getSnapshot();
        expect(first).toMatchObject({ state: 'error', error: { code: 'storage_full' } });
        const heard = listener.mock.calls.length;

        await vi.advanceTimersByTimeAsync(1000 + 2000 + 4000); // three retries
        expect(save).toHaveBeenCalledTimes(4);
        expect(listener).toHaveBeenCalledTimes(heard);
        expect(autosave.getSnapshot()).toBe(first);

        save.mockImplementation(() => Promise.reject(new Error('another failure')));
        await vi.advanceTimersByTimeAsync(8000);
        expect(autosave.getSnapshot().error).toEqual(new Error('another failure'));
        expect(listener).toHaveBeenCalledTimes(heard + 1);
        autosave.dispose();
    });

    it('starts a restored draft as "saved on this device"', () => {
        const { transport } = countingTransport();
        expect(controller(transport, { localVersion: 3 }).getSnapshot()).toMatchObject({
            state: 'saved',
            localVersion: 3,
        });
        expect(controller(transport).getSnapshot().state).toBe('idle');
    });

    it.each([
        ['pagehide', () => window.dispatchEvent(new Event('pagehide'))],
        ['freeze', () => document.dispatchEvent(new Event('freeze'))],
        [
            'visibilitychange to hidden',
            () => {
                Object.defineProperty(document, 'visibilityState', {
                    configurable: true,
                    value: 'hidden',
                });
                document.dispatchEvent(new Event('visibilitychange'));
            },
        ],
    ])('writes pending edits on %s', async (_name, leave) => {
        const { save, transport } = countingTransport();
        const autosave = controller(transport);
        autosave.start();
        autosave.update({ a: 1 });
        leave();
        await vi.advanceTimersByTimeAsync(0);
        expect(save).toHaveBeenCalledOnce();
        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            value: 'visible',
        });
        void autosave.stop();
    });

    it('replaceData drops pending edits and lets a write in flight land first', async () => {
        const { calls, transport } = manualTransport();
        const autosave = controller(transport);
        const listener = vi.fn();
        autosave.subscribe(listener);
        autosave.update({ a: 'mine' });
        await vi.advanceTimersByTimeAsync(300);
        autosave.update({ a: 'mine, later' });
        const replaced = autosave.replaceData({ a: 'server' });
        calls[0]?.resolve({ localVersion: 1 });
        await replaced;
        await vi.advanceTimersByTimeAsync(5000);
        expect(calls).toHaveLength(1);
        expect(autosave.data).toEqual({ a: 'server' });
        expect(autosave.getSnapshot().state).toBe('saved');
        expect(listener).toHaveBeenCalled();
    });
});
