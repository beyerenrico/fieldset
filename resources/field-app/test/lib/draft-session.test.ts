/** Draft sessions: one controller per draft, shared by screens, closed after pending local writes. */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { DeviceWipedError } from '../../src/lib/api/errors.ts';
import type { SaveRequest, SaveTransport } from '../../src/lib/autosave/types.ts';
import {
    disposeDraftSessions,
    endDraftSession,
    findDraftSession,
    openDraftSession,
    retainDraftSession,
    saveAndDisposeDraftSessions,
} from '../../src/lib/drafts/draft-session.ts';
import { draftLockName } from '../../src/lib/drafts/draft-lock.ts';
import { createWorkTracker } from '../../src/lib/drafts/work-tracker.ts';
import { displayState } from '../../src/hooks/useDraftEditor.ts';
import { reportAuthError, resetAuthNotice } from '../../src/lib/session/auth-events.ts';
import { SCOPE, installFakeLocks } from '../utils/a-store.ts';
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

function memoryTransport() {
    const requests: SaveRequest[] = [];
    let version = 0;
    let failing = false;
    const transport: SaveTransport = {
        save: (request) => {
            requests.push(request);
            if (failing) {
                return Promise.reject(new Error('IndexedDB refused the write'));
            }
            version += 1;
            return Promise.resolve({ localVersion: version });
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

function open(transport: SaveTransport) {
    return openDraftSession({
        uuid: 'draft-1',
        scope: SCOPE,
        formId: 3,
        formVersionId: 7,
        submission: makeSubmission({ uuid: 'draft-1', revision: 1, data: { a: 0 } }),
        data: { a: 0 },
        transport,
    });
}

afterEach(() => {
    disposeDraftSessions();
    resetAuthNotice();
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
    it('shares one session per draft and closes it after the last screen wrote locally', async () => {
        const { requests, transport } = memoryTransport();
        const session = open(transport);
        expect(open(transport)).toBe(session);
        expect(findDraftSession('draft-1')).toBe(session);
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
        expect(requests).toEqual([{ uuid: 'draft-1', formVersionId: 7, data: { a: 1 } }]);
    });

    it('waits for pending uploads and writes their result', async () => {
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
            { uuid: 'draft-1', formVersionId: 7, data: { a: 0, photo: 'att-1' } },
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

    it('stays open while the local write fails and closes once the retry wrote', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const { requests, transport, setFailing } = memoryTransport();
        setFailing(true);
        const session = open(transport);
        const release = retainDraftSession(session);
        session.controller.update({ a: 2 });
        release();
        await vi.advanceTimersByTimeAsync(400);
        expect(session.controller.getSnapshot().state).toBe('error');
        expect(open(transport)).toBe(session);
        setFailing(false);
        await vi.advanceTimersByTimeAsync(3000);
        expect(requests.at(-1)).toMatchObject({ data: { a: 2 } });
        await vi.waitFor(() => {
            expect(open(transport)).not.toBe(session);
        });
    });

    it('ends without writing on dispose (logout, wipe) and on endDraftSession', async () => {
        const { requests, transport } = memoryTransport();
        const session = open(transport);
        retainDraftSession(session);
        session.controller.update({ a: 2 });
        disposeDraftSessions();
        await tick();
        expect(retainDraftSession(session)).toBeTypeOf('function');
        expect(open(transport)).not.toBe(session);
        const next = open(transport);
        next.controller.update({ a: 3 });
        endDraftSession('draft-1');
        await new Promise((resolve) => setTimeout(resolve, 400));
        expect(findDraftSession('draft-1')).toBeUndefined();
        expect(requests).toEqual([]);
    });

    it('writes pending edits on this device when the session expires (reauth), then ends', async () => {
        const { requests, transport } = memoryTransport();
        const session = open(transport);
        retainDraftSession(session);
        session.controller.update({ a: 'typed just before expiry' });
        // App.tsx on a `reauth` notice (screens/session-loss.test.tsx covers the wiring).
        const saved = saveAndDisposeDraftSessions();
        // A dispose that follows (logout hooks) finds nothing left to drop.
        disposeDraftSessions();
        await saved;
        await vi.waitFor(() => {
            expect(requests).toEqual([
                { uuid: 'draft-1', formVersionId: 7, data: { a: 'typed just before expiry' } },
            ]);
        });
        expect(findDraftSession('draft-1')).toBeUndefined();
    });

    it('writes nothing after a wipe (the store was cleared)', async () => {
        const { requests, transport } = memoryTransport();
        const session = open(transport);
        retainDraftSession(session);
        session.controller.update({ a: 1 });
        reportAuthError(new DeviceWipedError('wiped'));
        disposeDraftSessions();
        await new Promise((resolve) => setTimeout(resolve, 400));
        expect(requests).toEqual([]);
    });
});

describe('one editing tab per draft (Web Lock fieldset-draft:<uuid>)', () => {
    /** Another tab holds the draft's lock until the returned function runs. */
    function holdInOtherTab(name: string): () => void {
        let letGo: () => void = () => undefined;
        void navigator.locks.request(
            name,
            { ifAvailable: true },
            () =>
                new Promise<void>((resolve) => {
                    letGo = resolve;
                }),
        );
        return () => {
            letGo();
        };
    }

    it('opens a draft read-only while another tab edits it and writes nothing', async () => {
        const locks = installFakeLocks();
        const letGo = holdInOtherTab(draftLockName('draft-1'));
        try {
            const { requests, transport } = memoryTransport();
            const session = open(transport);
            expect(await session.lock.ready).toBe('locked');
            retainDraftSession(session);
            session.controller.update({ a: 'not mine to write' });
            await session.controller.flush({ ensureCreated: true });
            await new Promise((resolve) => setTimeout(resolve, 400));
            expect(requests).toEqual([]);
            expect(displayState('saved', null, session.lock.getState())).toBe('locked');
        } finally {
            letGo();
            locks.uninstall();
        }
    });

    it('holds the lock while open and gives it up when the session ends', async () => {
        const locks = installFakeLocks();
        try {
            const { requests, transport } = memoryTransport();
            const session = open(transport);
            expect(await session.lock.ready).toBe('held');
            session.controller.update({ a: 1 });
            await session.controller.flush();
            expect(requests).toHaveLength(1);
            expect(locks.granted).toEqual(['fieldset-draft:draft-1']);

            endDraftSession('draft-1');
            await tick();
            const next = open(transport);
            expect(await next.lock.ready).toBe('held');
            expect(locks.granted).toEqual(['fieldset-draft:draft-1', 'fieldset-draft:draft-1']);
        } finally {
            locks.uninstall();
        }
    });

    it('works without Web Locks as before (no lock, writes go through)', async () => {
        const { requests, transport } = memoryTransport();
        const session = open(transport);
        expect(await session.lock.ready).toBe('unsupported');
        session.controller.update({ a: 1 });
        await session.controller.flush();
        expect(requests).toHaveLength(1);
        expect(displayState('saved', null, 'unsupported')).toBe('saved');
    });
});
