/** Draft sessions: one controller per draft, shared by screens, closed after pending work. */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { NetworkError, ReauthRequiredError } from '../../src/lib/api/errors.ts';
import type { Submission } from '../../src/lib/api/types.ts';
import type { SaveRequest, SaveTransport } from '../../src/lib/autosave/types.ts';
import {
    disposeDraftSessions,
    openDraftSession,
    retainDraftSession,
} from '../../src/lib/drafts/draft-session.ts';
import { createWorkTracker } from '../../src/lib/drafts/work-tracker.ts';
import { makeSubmission } from '../utils/fixtures.ts';

function deferred<T>() {
    let resolve: (value: T) => void = () => undefined;
    let reject: (error: unknown) => void = () => undefined;
    const promise = new Promise<T>((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
}

const tick = () => new Promise<void>((resolve) => setTimeout(resolve, 0));

function memoryTransport(revision = 1) {
    const requests: SaveRequest[] = [];
    let current = revision;
    let failing = false;
    const transport: SaveTransport = {
        save: (request) => {
            requests.push(request);
            if (failing) {
                return Promise.reject(new NetworkError('down'));
            }
            current += 1;
            return Promise.resolve(
                makeSubmission({ uuid: request.uuid, data: request.data, revision: current }),
            );
        },
    };
    return {
        requests,
        transport,
        setFailing: (value: boolean) => {
            failing = value;
        },
    };
}

function open(transport: SaveTransport, extra: { onSaved?: (s: Submission) => void } = {}) {
    return openDraftSession({
        uuid: 'draft-1',
        formVersionId: 7,
        submission: makeSubmission({ uuid: 'draft-1', revision: 1, data: { a: 0 } }),
        transport,
        ...extra,
    });
}

afterEach(() => {
    disposeDraftSessions();
    vi.useRealTimers();
});

describe('work tracker', () => {
    it('counts work, survives rejections and resolves idle after the results landed', async () => {
        const tracker = createWorkTracker();
        const listener = vi.fn();
        tracker.subscribe(listener);
        await tracker.idle();
        const first = deferred<string>();
        const second = deferred<string>();
        expect(tracker.track(first.promise)).toBe(first.promise);
        void tracker.track(second.promise).catch(() => undefined);
        expect(tracker.getPending()).toBe(2);
        let written = false;
        const done = vi.fn();
        void tracker.idle().then(done);
        void first.promise.then(() => {
            written = true;
        });
        first.resolve('ok');
        second.reject(new Error('failed'));
        await tick();
        await tick();
        expect(done).toHaveBeenCalledOnce();
        expect(written).toBe(true);
        expect(tracker.getPending()).toBe(0);
        expect(listener).toHaveBeenCalledTimes(4);
    });
});

describe('draft sessions', () => {
    it('shares one session per draft and closes it after the last screen saved', async () => {
        const saves: Submission[] = [];
        const { requests, transport } = memoryTransport();
        const session = open(transport, { onSaved: (saved) => saves.push(saved) });
        expect(open(transport)).toBe(session);
        const releaseFill = retainDraftSession(session);
        session.controller.update({ a: 1 });
        const releaseReview = retainDraftSession(session);
        releaseFill();
        releaseFill();
        await tick();
        expect(requests).toHaveLength(0);
        releaseReview();
        await vi.waitFor(() => {
            expect(open(transport)).not.toBe(session);
        });
        expect(requests).toEqual([
            { uuid: 'draft-1', formVersionId: 7, data: { a: 1 }, revision: 1 },
        ]);
        expect(saves.map((saved) => saved.revision)).toEqual([2]);
    });

    it('waits for pending uploads and saves their result with the same revision chain', async () => {
        const { requests, transport } = memoryTransport();
        const session = open(transport);
        const release = retainDraftSession(session);
        const upload = deferred<string>();
        void session.work.track(upload.promise).then((id) => {
            session.controller.update({ a: 0, photo: id });
        });
        release();
        await tick();
        expect(open(transport)).toBe(session);
        upload.resolve('att-1');
        await vi.waitFor(() => {
            expect(open(transport)).not.toBe(session);
        });
        expect(requests).toEqual([
            { uuid: 'draft-1', formVersionId: 7, data: { a: 0, photo: 'att-1' }, revision: 1 },
        ]);
    });

    it('keeps the session when a screen comes back while it is closing', async () => {
        const { transport } = memoryTransport();
        const session = open(transport);
        retainDraftSession(session)();
        const again = retainDraftSession(open(transport));
        await tick();
        await tick();
        expect(open(transport)).toBe(session);
        session.controller.update({ a: 5 });
        expect(session.controller.data).toEqual({ a: 5 });
        again();
    });

    it('stays open while offline and closes once the retry saved', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const { requests, transport, setFailing } = memoryTransport();
        setFailing(true);
        const session = open(transport);
        const release = retainDraftSession(session);
        session.controller.update({ a: 2 });
        release();
        await vi.advanceTimersByTimeAsync(100);
        expect(session.controller.getSnapshot().state).toBe('offline');
        expect(open(transport)).toBe(session);
        setFailing(false);
        await vi.advanceTimersByTimeAsync(6000);
        expect(requests.at(-1)).toMatchObject({ data: { a: 2 }, revision: 1 });
        await vi.waitFor(() => {
            expect(open(transport)).not.toBe(session);
        });
    });

    it('closes without saving after a lost session or on dispose', async () => {
        const save = vi.fn(() => Promise.reject(new ReauthRequiredError('gone')));
        const transport: SaveTransport = { save };
        const session = open(transport);
        const release = retainDraftSession(session);
        session.controller.update({ a: 1 });
        await session.controller.flush();
        release();
        await vi.waitFor(() => {
            expect(open(transport)).not.toBe(session);
        });
        expect(save).toHaveBeenCalledOnce();

        const next = open(transport);
        retainDraftSession(next);
        next.controller.update({ a: 2 });
        disposeDraftSessions();
        await tick();
        expect(retainDraftSession(next)).toBeTypeOf('function');
        expect(save).toHaveBeenCalledOnce();
        expect(open(transport)).not.toBe(next);
    });
});
