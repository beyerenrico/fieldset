/** Storage persistence helpers (ADR 0035 "Persistence and install"). */
import { afterEach, describe, expect, it } from 'vitest';
import {
    estimate,
    formatBytes,
    isPersisted,
    persist,
} from '../../../src/lib/storage/persistence.ts';
import { stubStorageManager } from '../../utils/c-rows.ts';

let restore: (() => void) | undefined;

afterEach(() => {
    restore?.();
    restore = undefined;
});

describe('storage persistence', () => {
    it('answers null without a Storage API (older Safari, jsdom)', async () => {
        expect(await isPersisted()).toBeNull();
        expect(await persist()).toBeNull();
        expect(await estimate()).toBeNull();
    });

    it('requests persistence once and reports usage', async () => {
        const stub = stubStorageManager({ persisted: false });
        restore = stub.restore;
        expect(await isPersisted()).toBe(false);
        expect(await persist()).toBe(true);
        expect(await persist()).toBe(true);
        expect(stub.persist).toHaveBeenCalledTimes(1);
        expect(await estimate()).toEqual({ usage: 12_500_000, quota: 2_000_000_000 });
    });

    it('reports a refused request and survives a throwing browser', async () => {
        const stub = stubStorageManager({ persisted: false, grant: false });
        restore = stub.restore;
        expect(await persist()).toBe(false);
        stub.persist.mockImplementation(() => Promise.reject(new Error('denied')));
        expect(await persist()).toBeNull();
    });

    it('formats sizes in the UI language', () => {
        expect(formatBytes(512, 'en')).toBe('512 byte');
        expect(formatBytes(12_500_000, 'en')).toBe('12.5 MB');
        expect(formatBytes(2_000_000_000, 'de').replace(/\s/gu, ' ')).toBe('2 GB'); // no-break space
        expect(formatBytes(-5, 'en')).toBe('0 byte');
    });
});
