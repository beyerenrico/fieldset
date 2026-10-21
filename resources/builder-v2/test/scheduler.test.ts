import type { JsonObject } from '@fieldset/fieldset-js';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { BuilderWire, SyncResult, SyncState } from '../src/contract.ts';
import { DEBOUNCE_MS, MAX_WAIT_MS, RETRY_BASE_MS, SyncScheduler } from '../src/sync/scheduler.ts';
import { syncResult } from './support/dom.ts';

let definition: JsonObject;
let states: SyncState[];
let results: SyncResult[];
let sync: ReturnType<typeof vi.fn<BuilderWire['sync']>>;
let save: ReturnType<typeof vi.fn<BuilderWire['save']>>;
let scheduler: SyncScheduler;

function edit(n: number): void {
    definition = { n };
    scheduler.notifyChange();
}

beforeEach(() => {
    vi.useFakeTimers();
    definition = { n: 0 };
    states = [];
    results = [];
    sync = vi.fn<BuilderWire['sync']>(() => Promise.resolve(syncResult({ checksum: 'c1' })));
    save = vi.fn<BuilderWire['save']>(() => Promise.resolve(syncResult({ checksum: 'c2' })));
    scheduler = new SyncScheduler(
        {
            wire: { sync, save },
            getDefinition: () => definition,
            onState: (state) => states.push(state),
            onResult: (result) => results.push(result),
        },
        'c0',
        definition,
    );
});

afterEach(() => {
    scheduler.destroy();
    vi.useRealTimers();
});

describe('sync scheduler', () => {
    it('debounces 800 ms and sends the latest definition with the acknowledged checksum', async () => {
        edit(1);
        expect(scheduler.state).toBe('pending');
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS - 1);
        edit(2);
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS - 1);
        expect(sync).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        expect(sync).toHaveBeenCalledWith('{"n":2}', 'c0', false);
        expect(scheduler.state).toBe('saved');
        expect(scheduler.checksum).toBe('c1');
        expect(scheduler.isDirty()).toBe(false);
        expect(states).toEqual(['pending', 'saving', 'saved']);
    });

    it('saves at least every 5 s while edits keep coming', async () => {
        for (let elapsed = 0; elapsed < MAX_WAIT_MS; elapsed += 500) {
            edit(elapsed);
            await vi.advanceTimersByTimeAsync(500);
        }
        expect(sync).toHaveBeenCalledTimes(1);
    });

    it('keeps one request in flight and sends the newest state afterwards', async () => {
        let release: (value: SyncResult) => void = () => undefined;
        sync.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));
        edit(1);
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);
        expect(scheduler.state).toBe('saving');
        edit(2);
        edit(3);
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS * 3);
        expect(sync).toHaveBeenCalledTimes(1);
        release(syncResult({ checksum: 'c1' }));
        await vi.advanceTimersByTimeAsync(0);
        expect(scheduler.state).toBe('pending');
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);
        expect(sync).toHaveBeenCalledTimes(2);
        expect(sync).toHaveBeenLastCalledWith('{"n":3}', 'c1', false);
    });

    it('returns to saved when an edit is undone back to the saved state', async () => {
        const saved = definition;
        edit(1);
        definition = saved;
        scheduler.notifyChange();
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS * 2);
        expect(sync).not.toHaveBeenCalled();
        expect(scheduler.isDirty()).toBe(false);
    });

    it('keeps an invalid definition local and does not resend it until it changes', async () => {
        sync.mockResolvedValueOnce(
            syncResult({
                status: 'invalid',
                checksum: 'c0',
                lint: { schema_errors: [{ path: '', message: 'bad' }], findings: [] },
            }),
        );
        edit(1);
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);
        expect(scheduler.state).toBe('invalid');
        expect(scheduler.checksum).toBe('c0');
        expect(results[0]!.lint.schema_errors).toHaveLength(1);
        scheduler.notifyChange();
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS * 2);
        expect(sync).toHaveBeenCalledTimes(1);
        expect(await scheduler.flush()).toBe('invalid');
        expect(scheduler.isDirty()).toBe(true);
        edit(2);
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);
        expect(sync).toHaveBeenCalledTimes(2);
        expect(scheduler.state).toBe('saved');
    });

    it('stops autosave on conflict until the user overwrites with force', async () => {
        sync.mockResolvedValueOnce(syncResult({ status: 'conflict', checksum: 'theirs' }));
        edit(1);
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);
        expect(scheduler.state).toBe('conflict');
        edit(2);
        await vi.advanceTimersByTimeAsync(MAX_WAIT_MS * 2);
        expect(sync).toHaveBeenCalledTimes(1);
        expect(await scheduler.flush()).toBe('conflict');
        expect(await scheduler.save()).toBe('conflict');
        expect(await scheduler.overwrite()).toBe('ok');
        expect(sync).toHaveBeenLastCalledWith('{"n":2}', 'c0', true);
        expect(scheduler.state).toBe('saved');
    });

    it('retries failed requests with backoff', async () => {
        sync.mockRejectedValueOnce(new Error('offline')).mockRejectedValueOnce(
            new Error('offline'),
        );
        edit(1);
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);
        expect(scheduler.state).toBe('error');
        await vi.advanceTimersByTimeAsync(RETRY_BASE_MS);
        expect(sync).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(RETRY_BASE_MS);
        expect(sync).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(RETRY_BASE_MS);
        expect(sync).toHaveBeenCalledTimes(3);
        expect(scheduler.state).toBe('saved');
    });

    it('flushes pending changes immediately and waits for a running request', async () => {
        edit(1);
        const flushed = scheduler.flush();
        expect(scheduler.state).toBe('saving');
        expect(await flushed).toBe('ok');
        expect(await scheduler.flush()).toBe('ok');
        expect(sync).toHaveBeenCalledTimes(1);
    });

    it('saves explicitly through save() even without changes, and validates on demand', async () => {
        expect(await scheduler.save()).toBe('ok');
        expect(save).toHaveBeenCalledWith('{"n":0}', 'c0', false);
        expect(scheduler.checksum).toBe('c2');
        expect(await scheduler.validate()).toBe('ok');
        expect(sync).toHaveBeenCalledWith('{"n":0}', 'c2', false);
    });
});
