/**
 * Engine guards (M6 gate F1 items 7–11): a send replaced in flight survives, a failed run does not
 * break the next one, the reauth pause holds the backoff wake, orphans and a stopped engine send
 * nothing, and a tab whose user is not the last signed-in user never sends (two tabs).
 */
import { http } from 'msw';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { db, getMeta } from '../../../src/lib/db/db.ts';
import { enqueueDraftSave, enqueueSend, opId } from '../../../src/lib/outbox/outbox.ts';
import { enqueueAndRun } from '../../../src/lib/submit/queue.ts';
import {
    ENQUEUE_DELAY_MS,
    SyncRunError,
    isSyncPaused,
    runSync,
    startSyncEngine,
    stopSyncEngine,
} from '../../../src/lib/sync/engine.ts';
import * as store from '../../../src/lib/sync/store.ts';
import { requestSync } from '../../../src/lib/sync/triggers.ts';
import { failWith, summarize } from '../../utils/a-backend.ts';
import {
    FAKE_TIMERS,
    SCOPE,
    draftRow,
    setupSyncScope,
    signInSession,
} from '../../utils/a-store.ts';
import { UUID, keyOf, setupBackend } from '../../utils/f1-sync.ts';
import { makeSubmission } from '../../utils/fixtures.ts';
import { server, setupTestServer } from '../../utils/server.ts';

setupTestServer();
setupSyncScope();

afterEach(async () => {
    await stopSyncEngine(); // a run still settling must not hold the tab's lock into the next test
    vi.useRealTimers();
    vi.restoreAllMocks();
});

const SEND_ID = opId('send', SCOPE.userId, UUID);

async function seedDraft(uuid: string, data = { customer: uuid }): Promise<void> {
    await db.drafts.put(draftRow({ uuid, data }));
    await enqueueDraftSave(SCOPE, uuid);
}

function submitted(backend: ReturnType<typeof setupBackend>): void {
    backend.state.submissions.set(UUID, makeSubmission({ uuid: UUID, status: 'submitted' }));
}

describe('a send replaced while in flight (item 7)', () => {
    it('keeps the new recipient queued when the old one went out', async () => {
        const backend = setupBackend();
        submitted(backend);
        await enqueueSend(SCOPE, UUID, { email: 'old@example.test' });
        server.use(
            http.post('*/api/v1/submissions/:uuid/send', async () => {
                if (backend.state.log.length === 0) {
                    await enqueueSend(SCOPE, UUID, { email: 'new@example.test' });
                }
                return undefined;
            }),
        );

        await runSync({ manual: true });
        const queued = await db.outbox.get(SEND_ID);
        expect(queued).toMatchObject({ state: 'pending', input: { email: 'new@example.test' } });

        await runSync({ manual: true });
        expect(backend.state.log.map((entry) => entry.body)).toEqual([
            { email: 'old@example.test' },
            { email: 'new@example.test' },
        ]);
        expect(backend.state.log[1]?.key).toBe(keyOf(queued));
        expect(await db.outbox.count()).toBe(0);
    });

    it('does not block the new recipient when the old one was refused', async () => {
        const backend = setupBackend();
        submitted(backend);
        backend.state.inject.set('POST send', () => {
            backend.state.inject.delete('POST send');
            return failWith(409, 'send_not_allowed')();
        });
        await enqueueSend(SCOPE, UUID, { email: 'old@example.test' });
        server.use(
            http.post('*/api/v1/submissions/:uuid/send', async () => {
                await enqueueSend(SCOPE, UUID, { email: 'new@example.test' });
                return undefined;
            }),
        );

        await runSync({ manual: true });

        expect(await db.outbox.get(SEND_ID)).toMatchObject({
            state: 'pending',
            lastError: null,
            input: { email: 'new@example.test' },
        });
    });
});

describe('a failed run (item 8)', () => {
    it('rejects with a SyncRunError, writes the error state and still runs the follow-up', async () => {
        const backend = setupBackend();
        await seedDraft('first');
        vi.spyOn(store, 'settleSubmission').mockRejectedValueOnce(new Error('IndexedDB refused'));

        const failing = runSync();
        const follow = runSync();

        await expect(failing).rejects.toBeInstanceOf(SyncRunError);
        expect(await follow).toMatchObject({ ran: true, state: 'idle' });
        expect(summarize(backend.state.log)).toEqual(['PUT create']);
        const again = runSync();
        await expect(again).resolves.toMatchObject({ ran: true });
    });

    it('enqueueAndRun rejects with a SyncRunError before any answer', async () => {
        setupBackend();
        vi.spyOn(store, 'markSyncing').mockRejectedValueOnce(new Error('IndexedDB refused'));
        await db.drafts.put(draftRow({ uuid: UUID }));

        const run = enqueueAndRun(
            { id: opId('draft.save', SCOPE.userId, UUID), submissionUuid: UUID },
            () => enqueueDraftSave(SCOPE, UUID),
        );

        await expect(run).rejects.toBeInstanceOf(SyncRunError);
        expect(await getMeta('sync')).toMatchObject({
            state: 'error',
            lastError: { code: 'unknown', message: 'IndexedDB refused' },
        });
    });
});

describe('reauth pause (item 9)', () => {
    it('cancels the backoff wake-up: nothing is sent until the next login', async () => {
        vi.useFakeTimers(FAKE_TIMERS);
        vi.spyOn(Math, 'random').mockReturnValue(0);
        const backend = setupBackend();
        backend.state.inject.set('PUT put', failWith(503, 'http_503'));
        await seedDraft('backing-off');
        const stop = startSyncEngine();
        await vi.waitFor(async () => {
            expect(await db.outbox.toArray()).toEqual([expect.objectContaining({ attempts: 1 })]);
        });

        backend.state.inject.clear();
        backend.state.loggedIn = false; // the session expires; device-status says reauth
        await seedDraft('second');
        await vi.advanceTimersByTimeAsync(ENQUEUE_DELAY_MS);
        await vi.waitFor(() => {
            expect(isSyncPaused()).toBe(true);
        });
        const sent = backend.state.log.length;

        await vi.advanceTimersByTimeAsync(10_000); // the first op's backoff ends meanwhile
        await new Promise((resolve) => setTimeout(resolve, 200)); // a request would land by now

        expect(backend.state.log).toHaveLength(sent);
        stop();
    });
});

describe('orphans and a stopped engine (item 10)', () => {
    it('drops a draft.save op whose draft is gone and never queues one for a missing draft', async () => {
        const backend = setupBackend();
        await db.outbox.put({
            ...SCOPE,
            id: opId('draft.save', SCOPE.userId, 'gone'),
            kind: 'draft.save',
            submissionUuid: 'gone',
            seq: 1,
            state: 'pending',
            attempts: 0,
            nextAttemptAt: 0,
            lastError: null,
            createdAt: 1,
        });

        expect(await runSync({ manual: true })).toMatchObject({ remaining: 0 });
        await enqueueDraftSave(SCOPE, 'discarded');

        expect(await db.outbox.count()).toBe(0);
        expect(backend.state.log).toEqual([]);
    });

    it('stopSyncEngine ends a run before its next submission and starts none until restarted', async () => {
        const backend = setupBackend();
        await seedDraft('a');
        await seedDraft('b');
        let release: () => void = () => undefined;
        const gate = new Promise<void>((resolve) => {
            release = resolve;
        });
        let arrived = 0;
        server.use(
            http.put('*/api/v1/submissions/:uuid', async () => {
                arrived++;
                await gate;
                return undefined;
            }),
        );
        const run = runSync({ manual: true });
        try {
            await vi.waitFor(() => {
                expect(arrived).toBe(1);
            });
            const stopped = stopSyncEngine();
            release();
            await stopped;
            await run;
        } finally {
            release();
        }

        expect(summarize(backend.state.log)).toEqual(['PUT create']);
        expect(await runSync({ manual: true })).toMatchObject({ ran: false });
        expect(backend.state.log).toHaveLength(1);
        const stop = startSyncEngine();
        await vi.waitFor(() => {
            expect(backend.state.log).toHaveLength(2);
        });
        stop();
    });
});

describe('a halted engine (item 10)', () => {
    it('ignores triggers after stopSyncEngine until the next login request', async () => {
        const backend = setupBackend();
        const stop = startSyncEngine();
        await vi.waitFor(async () => {
            expect((await getMeta('sync'))?.state).toBe('idle'); // the start run is over
        });
        await stopSyncEngine();
        await seedDraft('after-logout');
        requestSync('manual');
        window.dispatchEvent(new Event('online'));
        await new Promise((resolve) => setTimeout(resolve, ENQUEUE_DELAY_MS + 100));
        expect(backend.state.log).toEqual([]);

        requestSync('login'); // the next sign-in in this tab
        await vi.waitFor(() => {
            expect(summarize(backend.state.log)).toEqual(['PUT create']);
        });
        stop();
    });
});

describe('another user signed in elsewhere (item 11, two tabs)', () => {
    it("never sends tab A's outbox after tab B signed in as another user", async () => {
        const backend = setupBackend();
        await seedDraft(UUID);
        const stop = startSyncEngine();
        await vi.waitFor(async () => {
            expect(await db.outbox.count()).toBe(0);
        });
        await seedDraft('second');

        // Tab B (same IndexedDB) signs in as user 8: meta 'session' now names user 8; tab A's
        // in-memory user is still 7, and the login request reaches tab A too.
        await signInSession(8);
        requestSync('login');
        await vi.waitFor(() => {
            expect(isSyncPaused()).toBe(true);
        });
        expect(await runSync({ manual: true })).toMatchObject({ sent: 0, state: 'reauth' });
        requestSync('manual');
        await new Promise((resolve) => setTimeout(resolve, 50));

        expect(summarize(backend.state.log)).toEqual(['PUT create']);
        expect(isSyncPaused()).toBe(true);
        expect(await db.outbox.get(opId('draft.save', SCOPE.userId, 'second'))).toBeDefined();

        await signInSession(7); // user 7 signs in again
        requestSync('login');
        await vi.waitFor(async () => {
            expect(await db.outbox.count()).toBe(0);
        });
        expect(isSyncPaused()).toBe(false);
        stop();
    });

    it('stops between submissions when the session changes mid-run, and after a logout', async () => {
        const backend = setupBackend();
        await seedDraft('a');
        await seedDraft('b');
        server.use(
            http.put('*/api/v1/submissions/:uuid', async () => {
                await signInSession(8);
                return undefined;
            }),
        );

        await runSync({ manual: true });
        expect(summarize(backend.state.log)).toEqual(['PUT create']);

        await db.meta.delete('session'); // logout or wipe in another tab
        expect(await runSync({ manual: true })).toMatchObject({ sent: 0 });
        expect(backend.state.log).toHaveLength(1);
    });
});

describe('sync state (contract)', () => {
    it('is idle after a contact although another op still backs off after a network error', async () => {
        const backend = setupBackend();
        await seedDraft('fresh');
        await db.drafts.put(draftRow({ uuid: 'later' }));
        await db.outbox.put({
            ...SCOPE,
            id: opId('draft.save', SCOPE.userId, 'later'),
            kind: 'draft.save',
            submissionUuid: 'later',
            seq: 50,
            state: 'pending',
            attempts: 1,
            nextAttemptAt: Date.now() + 60_000,
            lastError: { status: null, code: 'network', message: 'offline' },
            createdAt: 1,
        });

        expect(await runSync()).toMatchObject({ sent: 1, state: 'idle' });
        expect(await getMeta('sync')).toMatchObject({ state: 'idle', lastError: null });

        backend.state.offline = true;
        await enqueueDraftSave(SCOPE, 'fresh');
        await db.drafts.update('fresh', { localVersion: 5 });
        expect(await runSync({ manual: true })).toMatchObject({ state: 'offline' });
        expect(await getMeta('sync')).toMatchObject({ state: 'offline' });
    });
});
