/** App update: pending edits of open drafts are written on this device; the sessions stay open. */
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { SaveRequest, SaveTransport } from '../../../src/lib/autosave/types.ts';
import {
    disposeDraftSessions,
    findDraftSession,
    flushDraftSessions,
    openDraftSession,
    retainDraftSession,
} from '../../../src/lib/drafts/draft-session.ts';
import { SCOPE } from '../../utils/a-store.ts';

afterEach(() => {
    disposeDraftSessions();
});

function recordingTransport() {
    const requests: SaveRequest[] = [];
    let version = 0;
    const transport: SaveTransport = {
        save: (request) => {
            requests.push(request);
            version += 1;
            return Promise.resolve({ localVersion: version });
        },
    };
    return { requests, transport };
}

function open(uuid: string, transport: SaveTransport) {
    const session = openDraftSession({
        uuid,
        scope: SCOPE,
        formId: 3,
        formVersionId: 7,
        submission: null,
        data: {},
        transport,
    });
    retainDraftSession(session);
    return session;
}

describe('flushDraftSessions', () => {
    it('writes the pending edits of every open draft and keeps the sessions open', async () => {
        const { requests, transport } = recordingTransport();
        const pagehide = vi.fn();
        window.addEventListener('pagehide', pagehide);
        const first = open('draft-1', transport);
        const second = open('draft-2', transport);
        first.controller.update({ a: 1 });
        second.controller.update({ b: 2 });

        await flushDraftSessions();

        window.removeEventListener('pagehide', pagehide);
        expect(requests).toEqual([
            { uuid: 'draft-1', formVersionId: 7, data: { a: 1 } },
            { uuid: 'draft-2', formVersionId: 7, data: { b: 2 } },
        ]);
        expect(pagehide).not.toHaveBeenCalled();
        expect(findDraftSession('draft-1')).toBe(first);
        expect(findDraftSession('draft-2')).toBe(second);

        // Still open: later edits are written as usual.
        first.controller.update({ a: 3 });
        await flushDraftSessions();
        expect(requests.at(-1)).toEqual({ uuid: 'draft-1', formVersionId: 7, data: { a: 3 } });
    });

    it('resolves at once without open drafts', async () => {
        await expect(flushDraftSessions()).resolves.toBeUndefined();
    });
});
