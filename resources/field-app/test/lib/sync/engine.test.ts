/** Sync engine: reauth pause, backoff, single flight across tabs, triggers, scope, sync meta. */
import { http, HttpResponse } from 'msw';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { db, getMeta } from '../../../src/lib/db/db.ts';
import { enqueueDraftSave, enqueueSend, opId } from '../../../src/lib/outbox/outbox.ts';
import { backoffDelay, MAX_DELAY_MS } from '../../../src/lib/sync/backoff.ts';
import {
    ENQUEUE_DELAY_MS,
    isSyncPaused,
    runSync,
    startSyncEngine,
} from '../../../src/lib/sync/engine.ts';
import { requestSync } from '../../../src/lib/sync/triggers.ts';
import { createSyncBackend, failWith, summarize } from '../../utils/a-backend.ts';
import { SCOPE, draftRow, installFakeLocks, setupSyncScope } from '../../utils/a-store.ts';
import { makeSubmission } from '../../utils/fixtures.ts';
import { server, setupTestServer } from '../../utils/server.ts';

setupTestServer();
setupSyncScope();

const UUID = '0192f1c4-0000-7000-8000-00000000c001';
const SAVE_ID = opId('draft.save', SCOPE.userId, UUID);

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

function setup() {
    const backend = createSyncBackend();
    server.use(...backend.handlers);
    return backend;
}

async function seedDraft(overrides: Parameters<typeof draftRow>[0] = {}): Promise<void> {
    await db.drafts.put(draftRow({ uuid: UUID, data: { customer: 'ACME' }, ...overrides }));
    await enqueueDraftSave(SCOPE, UUID);
}

describe('lost session (reauth)', () => {
    it('pauses with rows untouched and resumes on login', async () => {
        const backend = setup();
        backend.state.loggedIn = false;
        await seedDraft();
        const before = await db.outbox.get(SAVE_ID);
        const stop = startSyncEngine();

        await vi.waitFor(async () => {
            expect((await getMeta('sync'))?.state).toBe('reauth');
        });
        expect(isSyncPaused()).toBe(true);
        expect(await db.outbox.get(SAVE_ID)).toEqual(before);
        expect(await db.drafts.get(UUID)).toMatchObject({ localVersion: 1, syncedVersion: 0 });
        expect(summarize(backend.state.log)).toEqual(['PUT create']);

        // Automatic triggers wait for the login.
        window.dispatchEvent(new Event('online'));
        requestSync('visible');
        await new Promise((resolve) => setTimeout(resolve, 50));
        expect(backend.state.log).toHaveLength(1);

        backend.state.loggedIn = true;
        requestSync('login');
        await vi.waitFor(async () => {
            expect(await db.outbox.count()).toBe(0);
        });
        expect(isSyncPaused()).toBe(false);
        expect(summarize(backend.state.log)).toEqual(['PUT create', 'PUT create']);
        expect((await getMeta('sync'))?.state).toBe('idle');
        stop();
    });
});

describe('backoff', () => {
    it('grows as min(15 min, 5 s·2^(n−1)) plus up to 10 % jitter', () => {
        expect([1, 2, 3, 4].map((n) => backoffDelay(n, () => 0))).toEqual([
            5000, 10_000, 20_000, 40_000,
        ]);
        expect(backoffDelay(1, () => 1)).toBe(5500);
        expect(backoffDelay(30, () => 0)).toBe(MAX_DELAY_MS);
    });

    it('retries 5xx after the delay (fake timers) and starts over on a manual run', async () => {
        vi.useFakeTimers({
            shouldAdvanceTime: true,
            toFake: ['setTimeout', 'clearTimeout', 'Date'],
        });
        vi.spyOn(Math, 'random').mockReturnValue(0);
        const backend = setup();
        backend.state.inject.set('PUT put', failWith(503, 'http_503'));
        await seedDraft();

        expect(await runSync()).toMatchObject({ sent: 0, remaining: 1, state: 'error' });
        const first = await db.outbox.get(SAVE_ID);
        expect(first).toMatchObject({ attempts: 1, lastError: { status: 503, code: 'http_503' } });
        expect((first?.nextAttemptAt ?? 0) - Date.now()).toBeGreaterThan(4900);

        await runSync(); // not due yet
        expect(backend.state.log).toHaveLength(1);
        await vi.advanceTimersByTimeAsync(5000);
        await runSync();
        expect(backend.state.log).toHaveLength(2);
        expect(await db.outbox.get(SAVE_ID)).toMatchObject({ attempts: 2 });

        await runSync({ manual: true }); // "Sync now" ignores the delay; the backoff starts over
        expect(backend.state.log).toHaveLength(3);
        expect(await db.outbox.get(SAVE_ID)).toMatchObject({ attempts: 1 });

        backend.state.inject.clear();
        await vi.advanceTimersByTimeAsync(5000);
        expect(await runSync()).toMatchObject({ sent: 1, remaining: 0, state: 'idle' });
    });

    it('wakes itself when the earliest backoff ends and honours Retry-After', async () => {
        vi.useFakeTimers({
            shouldAdvanceTime: true,
            toFake: ['setTimeout', 'clearTimeout', 'Date'],
        });
        vi.spyOn(Math, 'random').mockReturnValue(0);
        setup();
        let calls = 0;
        server.use(
            http.put('*/api/v1/submissions/:uuid', () => {
                calls++;
                return calls === 1
                    ? HttpResponse.json(
                          {
                              success: false,
                              data: null,
                              error: { code: 'too_many_requests', message: 'slow down' },
                              meta: null,
                          },
                          { status: 429, headers: { 'Retry-After': '30' } },
                      )
                    : undefined;
            }),
        );
        await seedDraft();
        const stop = startSyncEngine();
        await vi.waitFor(async () => {
            expect(await db.outbox.get(SAVE_ID)).toMatchObject({ attempts: 1 });
        });
        const op = await db.outbox.get(SAVE_ID);
        expect((op?.nextAttemptAt ?? 0) - Date.now()).toBeGreaterThan(29_000);

        await vi.advanceTimersByTimeAsync(29_000);
        expect(calls).toBe(1);
        await vi.advanceTimersByTimeAsync(1500);
        await vi.waitFor(async () => {
            expect(await db.outbox.count()).toBe(0);
        });
        expect(calls).toBe(2);
        stop();
    });
});

describe('single flight', () => {
    it('sends once when two runs start at the same time in one tab', async () => {
        const backend = setup();
        await seedDraft();
        const [a, b] = await Promise.all([runSync(), runSync()]);
        expect(backend.state.log).toHaveLength(1);
        expect(a.sent + b.sent).toBeGreaterThanOrEqual(1);
        expect(await db.outbox.count()).toBe(0);
    });

    it('sends once when two tabs (two engines) run concurrently under the Web Lock', async () => {
        const locks = installFakeLocks();
        const backend = setup();
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
        try {
            await seedDraft();
            // A second tab: its own module instances (engine, store, scope), the same IndexedDB.
            vi.resetModules();
            const scopeB = await import('../../../src/lib/db/scope.ts');
            const engineB = await import('../../../src/lib/sync/engine.ts');
            scopeB.setCurrentUserId(SCOPE.userId);

            const tabA = runSync();
            await vi.waitFor(() => {
                expect(arrived).toBe(1);
            });
            const tabB = await engineB.runSync();
            release();
            const resultA = await tabA;

            expect(tabB.ran).toBe(false);
            expect(resultA).toMatchObject({ ran: true, sent: 1 });
            expect(backend.state.log).toHaveLength(1);
            expect(locks.granted).toEqual(['fsa-sync']);
        } finally {
            release();
            locks.uninstall();
        }
    });

    it('runs once more after a run when a request arrived meanwhile', async () => {
        const backend = setup();
        await seedDraft();
        const first = runSync();
        await db.drafts.put(draftRow({ uuid: 'second', data: { a: 1 } }));
        await enqueueDraftSave(SCOPE, 'second');
        const follow = runSync();
        await first;
        await follow;
        expect(summarize(backend.state.log)).toEqual(['PUT create', 'PUT create']);
    });
});

describe('triggers and scope', () => {
    it('runs after an enqueue (debounced) and on the online event', async () => {
        const backend = setup();
        const stop = startSyncEngine();
        await vi.waitFor(async () => {
            expect((await getMeta('sync'))?.state).toBe('idle'); // the start run is over
        });
        await seedDraft();
        await new Promise((resolve) => setTimeout(resolve, ENQUEUE_DELAY_MS / 2));
        expect(backend.state.log).toHaveLength(0);
        await vi.waitFor(() => {
            expect(backend.state.log).toHaveLength(1);
        });
        await db.drafts.put(draftRow({ uuid: 'other', data: { b: 1 } }));
        await db.outbox.put({
            ...SCOPE,
            id: opId('draft.save', SCOPE.userId, 'other'),
            kind: 'draft.save',
            submissionUuid: 'other',
            seq: 99,
            state: 'pending',
            attempts: 0,
            nextAttemptAt: 0,
            lastError: null,
            createdAt: Date.now(),
        });
        window.dispatchEvent(new Event('online'));
        await vi.waitFor(() => {
            expect(backend.state.log).toHaveLength(2);
        });
        stop();
    });

    it("sends only the signed-in user's rows, each with its row's organization", async () => {
        const backend = setup();
        await db.drafts.put(draftRow({ uuid: 'mine-org-2', orgId: 2, data: { a: 1 } }));
        await enqueueDraftSave({ orgId: 2, userId: 7 }, 'mine-org-2');
        await db.drafts.put(draftRow({ uuid: 'someone-else', userId: 8, data: { b: 1 } }));
        await enqueueDraftSave({ orgId: 1, userId: 8 }, 'someone-else');

        const result = await runSync({ manual: true });

        expect(backend.state.log.map((entry) => [entry.path, entry.org])).toEqual([
            ['/api/v1/submissions/mine-org-2', '2'],
        ]);
        expect(result.remaining).toBe(0);
        expect(await db.outbox.get(opId('draft.save', 8, 'someone-else'))).toBeDefined();
    });

    it('writes the sync meta on every run', async () => {
        setup();
        expect(await getMeta('sync')).toBeUndefined();
        await runSync();
        expect(await getMeta('sync')).toMatchObject({
            state: 'idle',
            lastError: null,
            lastRunAt: expect.any(Number) as unknown,
            lastSuccessAt: expect.any(Number) as unknown,
        });
    });

    it('purges an organization whose membership is gone (403 organization_forbidden)', async () => {
        const backend = setup();
        backend.state.inject.set('PUT put', failWith(403, 'organization_forbidden'));
        server.use(
            http.get('*/api/v1/me', () =>
                HttpResponse.json({
                    success: true,
                    data: { user: { id: 7 }, organizations: [{ id: 2 }] },
                    error: null,
                    meta: null,
                }),
            ),
        );
        await seedDraft();
        await runSync({ manual: true });
        expect(await db.drafts.get(UUID)).toBeUndefined();
        expect(await db.outbox.count()).toBe(0);
    });

    it('sends a queued send only after the submission was submitted', async () => {
        const backend = setup();
        backend.state.submissions.set(UUID, makeSubmission({ uuid: UUID, status: 'submitted' }));
        await enqueueSend(SCOPE, UUID, { email: 'kunde@example.test', name: 'Kunde' });
        const op = await db.outbox.get(opId('send', SCOPE.userId, UUID));
        await runSync({ manual: true });
        expect(backend.state.log).toEqual([
            expect.objectContaining({
                path: `/api/v1/submissions/${UUID}/send`,
                key: op?.kind === 'send' ? op.request.idempotencyKey : 'missing',
                body: { email: 'kunde@example.test', name: 'Kunde' },
            }),
        ]);
        expect(await db.outbox.count()).toBe(0);
    });
});
