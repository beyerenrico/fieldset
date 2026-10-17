/**
 * The sync engine (ADR 0035). It sends the outbox of the signed-in user (every organization, each
 * request with its row's organization), one submission at a time in the order of process.ts.
 * One tab syncs at a time (lock.ts); a run requested during a run follows it once. Nothing is sent
 * unless this tab's user is the last signed-in user (session-guard.ts).
 */
import { DeviceWipedError, ReauthRequiredError } from '../api/errors.ts';
import type { SyncState } from '../db/schema.ts';
import { getCurrentUserId } from '../db/scope.ts';
import { opsOfUser } from '../outbox/outbox.ts';
import { reportAuthError } from '../session/auth-events.ts';
import { isFatal, type RunContext, type StepResult } from './failures.ts';
import { withSyncLock } from './lock.ts';
import { processSubmission } from './process.ts';
import { SyncRunError } from './run-error.ts';
import { isCurrentSession } from './session-guard.ts';
import { resetStaleSyncing, settleSubmission } from './store.ts';
import {
    isRetrying,
    readSyncState,
    stateAfter,
    writeRunFailure,
    writeSyncMeta,
} from './sync-meta.ts';
import { onSyncRequested, type SyncReason } from './triggers.ts';

export { discardLocalSubmission, resolveConflict } from './conflicts.ts';
export { SyncRunError } from './run-error.ts';

export interface SyncRunResult {
    readonly ran: boolean;
    readonly sent: number;
    readonly blocked: number;
    readonly remaining: number;
    readonly state: SyncState;
}

/** Bursts of autosave enqueues become one run. */
export const ENQUEUE_DELAY_MS = 1000;
export const INTERVAL_MS = 60_000;
/** These ignore the backoff (it starts over). */
const MANUAL_REASONS: ReadonlySet<SyncReason> = new Set(['manual', 'online', 'login']);
/** These lift a reauth pause, if this tab's user is the signed-in one. */
const RESUMING_REASONS: ReadonlySet<SyncReason> = new Set(['manual', 'login', 'start']);

/**
 * `reauth`: the session expired; automatic triggers wait for the next login or "Sync now".
 * `stale`: this tab's user is not the last signed-in user; every trigger checks again.
 */
type Pause = 'reauth' | 'stale';

let active: Promise<SyncRunResult> | null = null;
let rerun: Promise<SyncRunResult> | null = null;
let rerunManual = false;
let paused: Pause | null = null;
/**
 * Set by stopSyncEngine() (logout, wipe): no run starts and a run in flight stops before its next
 * submission, until the next login request or startSyncEngine().
 */
let halted = false;
let generation = 0;
let stopTriggers: (() => void) | null = null;
let enqueueTimer: ReturnType<typeof setTimeout> | null = null;
let wakeTimer: ReturnType<typeof setTimeout> | null = null;

function clearTimers(): void {
    for (const timer of [enqueueTimer, wakeTimer]) {
        if (timer !== null) {
            clearTimeout(timer);
        }
    }
    enqueueTimer = null;
    wakeTimer = null;
}

function pause(reason: Pause): void {
    paused = reason;
    clearTimers(); // a backoff wake or a debounced enqueue must not bypass the pause
}

function reportStop(stop: StepResult | null): void {
    if (stop === 'reauth') {
        pause('reauth');
        reportAuthError(new ReauthRequiredError('Please sign in again.'));
    } else if (stop === 'wiped') {
        reportAuthError(new DeviceWipedError('This device was signed out and its data removed.'));
    } else if (stop === 'stale') {
        pause('stale');
    }
}

async function processAll(
    ctx: RunContext,
    run: number,
): Promise<{ stop: StepResult | null; blocked: number }> {
    const ops = await opsOfUser(ctx.userId);
    const uuids = [...new Set(ops.map((op) => op.submissionUuid))];
    let blocked = 0;
    for (const uuid of uuids) {
        if (run !== generation || !(await isCurrentSession(ctx.userId))) {
            return { stop: 'stale', blocked };
        }
        const result = await processSubmission(ctx, uuid);
        if (result !== 'wiped') {
            await settleSubmission(uuid);
        }
        blocked += result === 'blocked' ? 1 : 0;
        if (isFatal(result)) {
            return { stop: result, blocked };
        }
    }
    return { stop: null, blocked };
}

const runResult = (state: SyncState, values: Partial<SyncRunResult> = {}): SyncRunResult => ({
    ran: true,
    sent: 0,
    blocked: 0,
    remaining: 0,
    state,
    ...values,
});

async function runOnce(manual: boolean): Promise<SyncRunResult> {
    const userId = getCurrentUserId();
    const lastRunAt = Date.now();
    const run = generation;
    if (halted) {
        return runResult('idle', { ran: false });
    }
    if (userId === null) {
        await writeSyncMeta({ state: 'idle', lastRunAt });
        return runResult('idle');
    }
    if (!(await isCurrentSession(userId))) {
        pause('stale'); // the sync meta belongs to the signed-in user: left as it is
        return runResult('reauth');
    }
    await resetStaleSyncing(userId); // we hold the lock: no run of this user is in flight
    if (!navigator.onLine) {
        await writeSyncMeta({ state: 'offline', lastRunAt });
        return runResult('offline', { remaining: (await opsOfUser(userId)).length });
    }
    const before = await readSyncState();
    await writeSyncMeta({ state: 'running', lastRunAt });
    const ctx: RunContext = { manual, now: lastRunAt, userId, sent: 0, contacted: false };
    const { stop, blocked } = await processAll(ctx, run);
    reportStop(stop);
    if (stop === 'wiped') {
        return runResult('idle', { sent: ctx.sent, blocked });
    }
    if (stop === 'stale') {
        await writeSyncMeta({ state: before });
        return runResult('reauth', { sent: ctx.sent, blocked });
    }
    const left = await opsOfUser(userId);
    const state = stateAfter(stop, ctx, left, before);
    await writeSyncMeta({
        state,
        lastError: left.find(isRetrying)?.lastError ?? null,
        ...(stop === null ? { lastSuccessAt: Date.now() } : {}),
    });
    return runResult(state, { sent: ctx.sent, blocked, remaining: left.length });
}

/** Automatic runs may start: the engine is started and not paused (read anew after every await). */
function mayWake(): boolean {
    return stopTriggers !== null && paused === null && !halted;
}

/** Wakes the engine when the earliest backoff ends (the 60 s interval is only a fallback). */
async function scheduleWake(): Promise<void> {
    const userId = getCurrentUserId();
    if (!mayWake() || userId === null) {
        return;
    }
    const now = Date.now();
    const next = (await opsOfUser(userId))
        .filter((op) => op.state === 'pending' && op.nextAttemptAt > now)
        .reduce((min, op) => Math.min(min, op.nextAttemptAt), Infinity);
    if (wakeTimer !== null) {
        clearTimeout(wakeTimer);
        wakeTimer = null;
    }
    if (Number.isFinite(next) && mayWake()) {
        wakeTimer = setTimeout(() => {
            wakeTimer = null;
            if (mayWake()) {
                void runSync().catch(logRunFailure);
            }
        }, next - now);
    }
}

async function execute(manual: boolean): Promise<SyncRunResult> {
    const lock = { held: false };
    try {
        const outcome = await withSyncLock(() => {
            lock.held = true;
            return runOnce(manual);
        });
        // null: another tab holds the lock and syncs the same outbox.
        return outcome ?? runResult('running', { ran: false });
    } catch (error) {
        await writeRunFailure(error);
        throw new SyncRunError(error);
    } finally {
        if (lock.held) {
            await scheduleWake().catch((error: unknown) => {
                console.warn('Scheduling the next sync failed', error);
            });
        }
    }
}

/**
 * Runs the outbox now. During a run in this tab, one follow-up run is queued and returned. Rejects
 * with a SyncRunError when the run failed unexpectedly (the follow-up runs anyway).
 */
export function runSync(options: { readonly manual?: boolean } = {}): Promise<SyncRunResult> {
    const manual = options.manual === true;
    if (active !== null) {
        rerunManual ||= manual;
        rerun ??= active
            .catch(() => undefined) // the failed run's caller got its rejection
            .then(() => {
                rerun = null;
                const next = rerunManual;
                rerunManual = false;
                return runSync({ manual: next });
            });
        return rerun;
    }
    active = execute(manual).finally(() => {
        active = null;
    });
    return active;
}

function dispatch(reason: SyncReason): void {
    if (reason !== 'enqueue') {
        void runSync({ manual: MANUAL_REASONS.has(reason) }).catch(logRunFailure);
        return;
    }
    if (enqueueTimer !== null) {
        clearTimeout(enqueueTimer);
    }
    enqueueTimer = setTimeout(() => {
        enqueueTimer = null;
        if (paused === null && !halted) {
            void runSync().catch(logRunFailure);
        }
    }, ENQUEUE_DELAY_MS);
}

function logRunFailure(error: unknown): void {
    console.warn('A sync run failed', error);
}

async function onRequest(reason: SyncReason): Promise<void> {
    const resuming = RESUMING_REASONS.has(reason);
    if (halted && reason !== 'login') {
        return;
    }
    halted = false; // a new session starts after a logout without a reload
    if (paused === 'reauth' && !resuming) {
        return;
    }
    if (paused !== null || resuming) {
        const userId = getCurrentUserId();
        if (userId !== null && !(await isCurrentSession(userId))) {
            pause('stale'); // e.g. a login in another tab as someone else: not this tab's session
            return;
        }
        paused = null;
    }
    dispatch(reason);
}

async function onInterval(): Promise<void> {
    const userId = getCurrentUserId();
    if (paused !== null || userId === null || document.visibilityState !== 'visible') {
        return;
    }
    if ((await opsOfUser(userId)).length > 0) {
        void runSync().catch(logRunFailure);
    }
}

function wireTriggers(): () => void {
    const request = (reason: SyncReason): void => {
        void onRequest(reason);
    };
    const stopRequests = onSyncRequested(request);
    const onOnline = (): void => {
        request('online');
    };
    const onVisibility = (): void => {
        if (document.visibilityState === 'visible') {
            request('visible');
        }
    };
    window.addEventListener('online', onOnline);
    document.addEventListener('visibilitychange', onVisibility);
    const interval = setInterval(() => void onInterval(), INTERVAL_MS);
    return () => {
        stopRequests();
        window.removeEventListener('online', onOnline);
        document.removeEventListener('visibilitychange', onVisibility);
        clearInterval(interval);
    };
}

/** Wires the triggers (requests from every tab, online, visible, interval) and returns a stop function. */
export function startSyncEngine(): () => void {
    stopTriggers?.();
    halted = false;
    stopTriggers = wireTriggers();
    void onRequest('start');
    return () => {
        stopTriggers?.();
        stopTriggers = null;
        void stopSyncEngine();
    };
}

/**
 * Halts the engine before local data is cleared (logout, wipe): timers end, no run starts, and a
 * run in flight stops before its next submission. The next login request (or startSyncEngine())
 * resumes it. Safe to call more than once; resolves once the run in flight (if any) has settled.
 */
export async function stopSyncEngine(): Promise<void> {
    halted = true;
    generation += 1;
    clearTimers();
    await active?.catch(() => undefined);
}

/** True while a lost session (or another user's login in another tab) holds automatic syncing back. */
export function isSyncPaused(): boolean {
    return paused !== null;
}

/** Tests only: forget the pause, the stop and any run in flight. */
export function resetSyncEngineForTests(): void {
    paused = null;
    halted = false;
    active = null;
    rerun = null;
    rerunManual = false;
}
