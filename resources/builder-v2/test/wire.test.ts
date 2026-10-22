import { describe, expect, it, vi } from 'vitest';
import type { SyncResult } from '../src/contract.ts';
import { createWire, parseSyncResult, WireError } from '../src/sync/wire.ts';
import { contractFixture } from './support/fixtures.ts';

describe('wire adapter', () => {
    it('parses the contract sync result', () => {
        expect(parseSyncResult(contractFixture('sync-result'))).toEqual(
            contractFixture('sync-result') as SyncResult,
        );
    });

    it('rejects malformed answers (an escaped server exception returns null)', () => {
        expect(() => parseSyncResult(null)).toThrow(WireError);
        expect(() => parseSyncResult({ status: 'weird', checksum: 'x' })).toThrow(WireError);
        expect(() => parseSyncResult({ status: 'saved' })).toThrow(WireError);
        expect(
            parseSyncResult({
                status: 'conflict',
                checksum: 'x',
                lint: {
                    findings: [{ code: 'c', path: '/p', severity: 'error' }, 7],
                    schema_errors: [{ path: '' }],
                },
            }),
        ).toEqual({
            status: 'conflict',
            checksum: 'x',
            lint: {
                schema_errors: [{ path: '', message: '' }],
                findings: [{ code: 'c', path: '/p', severity: 'error' }],
            },
            savedAt: null,
        });
    });

    it('sends one Livewire call at a time, so a sync never shares a request with inspect', async () => {
        // Livewire bundles calls made together into one request, and a #[Json] method (sync, save)
        // skips the render of the whole request: an inspect() sent with it never re-rendered the
        // inspector (CI run 39796441261).
        const order: string[] = [];
        let answerSync: (value: unknown) => void = () => undefined;
        const proxy = {
            sync: vi.fn(() => {
                order.push('sync');
                return new Promise((resolve) => {
                    answerSync = resolve;
                });
            }),
            inspect: vi.fn(() => {
                order.push('inspect');
                return Promise.resolve();
            }),
        };
        const wire = createWire(proxy);
        const synced = wire.sync('{}', 'c');
        const inspected = wire.inspect('element', 'n1', '{}', '{}');
        await new Promise((resolve) => setTimeout(resolve));
        expect(order).toEqual(['sync']);

        answerSync({ status: 'saved', checksum: 'a', lint: {}, savedAt: null });
        await Promise.all([synced, inspected]);
        expect(order).toEqual(['sync', 'inspect']);
    });

    it('sends the next call after a failed one', async () => {
        const proxy = {
            sync: vi.fn(() => Promise.reject(new Error('offline'))),
            inspect: vi.fn(() => Promise.resolve()),
        };
        const wire = createWire(proxy);
        const synced = wire.sync('{}', 'c');
        const inspected = wire.inspect('settings', null, '{}', '{}');
        await expect(synced).rejects.toThrow('offline');
        await inspected;
        expect(proxy.inspect).toHaveBeenCalledOnce();
    });

    it('calls the Livewire methods with the contract arguments', async () => {
        const proxy = {
            sync: vi.fn(() =>
                Promise.resolve({
                    status: 'saved',
                    checksum: 'a',
                    lint: {},
                    savedAt: '2026-10-18T08:00:00+00:00',
                }),
            ),
            save: vi.fn(() =>
                Promise.resolve({ status: 'unchanged', checksum: 'a', lint: {}, savedAt: null }),
            ),
            inspect: vi.fn(() => Promise.resolve()),
            commitInspector: vi.fn(() => Promise.resolve()),
            cloneNode: vi.fn(() => Promise.resolve('{"id":"x"}')),
            mountAction: vi.fn(() => Promise.resolve()),
        };
        const wire = createWire(proxy);
        expect((await wire.sync('{}', 'c')).status).toBe('saved');
        expect(proxy.sync).toHaveBeenCalledWith('{}', 'c', false);
        await wire.save('{}', 'c', true);
        expect(proxy.save).toHaveBeenCalledWith('{}', 'c', true);
        await wire.inspect('settings', null, '{}', '{}');
        expect(proxy.inspect).toHaveBeenCalledWith('settings', null, '{}', '{}');
        await wire.commitInspector('{}');
        expect(await wire.cloneNode('{}', '[]')).toBe('{"id":"x"}');
        await wire.mountAction('publish', { checksum: 'c' });
        expect(proxy.mountAction).toHaveBeenCalledWith('publish', { checksum: 'c' });
    });

    it('fails clearly for missing methods and non-string clones', async () => {
        await expect(createWire({}).sync('{}', 'c')).rejects.toThrow(WireError);
        await expect(
            createWire({ cloneNode: () => Promise.resolve(null) }).cloneNode('{}', '[]'),
        ).rejects.toThrow(WireError);
    });
});
