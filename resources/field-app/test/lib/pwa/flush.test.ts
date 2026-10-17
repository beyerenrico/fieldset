import { afterEach, describe, expect, it, vi } from 'vitest';

afterEach(() => {
    vi.doUnmock('../../../src/lib/drafts/draft-session.ts');
    vi.resetModules();
});

describe('flushing open drafts before an update', () => {
    it('uses flushDraftSessions when the draft module exports it', async () => {
        const flushDraftSessions = vi.fn(() => Promise.resolve());
        vi.doMock('../../../src/lib/drafts/draft-session.ts', () => ({ flushDraftSessions }));
        const pagehide = vi.fn();
        window.addEventListener('pagehide', pagehide);
        const { flushOpenDrafts } = await import('../../../src/lib/pwa/flush-drafts.ts');
        await flushOpenDrafts();
        window.removeEventListener('pagehide', pagehide);
        expect(flushDraftSessions).toHaveBeenCalledOnce();
        expect(pagehide).not.toHaveBeenCalled();
    });

    it('falls back to a synthetic pagehide that the autosave flushes on', async () => {
        // A module namespace without the export reads as undefined (Vitest mocks need the key).
        vi.doMock('../../../src/lib/drafts/draft-session.ts', () => ({
            flushDraftSessions: undefined,
        }));
        const pagehide = vi.fn();
        window.addEventListener('pagehide', pagehide);
        const { flushOpenDrafts } = await import('../../../src/lib/pwa/flush-drafts.ts');
        await flushOpenDrafts();
        window.removeEventListener('pagehide', pagehide);
        expect(pagehide).toHaveBeenCalledOnce();
    });
});
