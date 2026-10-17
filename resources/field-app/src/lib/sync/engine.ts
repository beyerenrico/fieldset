/**
 * The sync engine (ADR 0035). It sends the outbox of the signed-in user (every organization, each
 * request with its row's organization), one submission at a time in the order of process.ts.
 * One tab syncs at a time (lock.ts); a run requested during a run follows it once.
 */
import { DeviceWipedError, ReauthRequiredError } from '../api/errors.ts';
import { getMeta, setMeta } from '../db/db.ts';
import type { OutboxError, SyncMeta, SyncState } from '../db/schema.ts';
import { getCurrentUserId } from '../db/scope.ts';
import { opsOfUser } from '../outbox/outbox.ts';
import { reportAuthError } from '../session/auth-events.ts';
import { isFatal, type RunContext, type StepResult } from './failures.ts';
import { withSyncLock } from './lock.ts';
import { processSubmission } from './process.ts';
import { settleSubmission } from './store.ts';
import { onSyncRequested, type SyncReason } from './triggers.ts';

export { discardLocalSubmission, resolveConflict } from './conflicts.ts';

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
/** These ignore the backoff (it starts over) and lift a reauth pause. */
const MANUAL_REASONS: ReadonlySet<SyncReason> = new Set(['manual', 'online', 'login']);
const RESUMING_REASONS: ReadonlySet<SyncReason> = new Set(['manual', 'login', 'start']);

let active: Promise<SyncRunResult> | null = null;
let rerun: Promise<SyncRunResult> | null = null;
let rerunManual = false;
/** Set by a lost session (reauth): automatic triggers wait for the next login or "Sync now". */
let paused = false;
let started = false;
let enqueueTimer: ReturnType<typeof setTimeout> | null = null;
let wakeTimer: ReturnType<typeof setTimeout> | null = null;

async function writeSyncMeta(patch: Partial<SyncMeta>): Promise<void> {
    const previous = (await getMeta('sync')) ?? {
        state: 'idle',
        lastRunAt: null,
        lastSuccessAt: null,
        lastError: null,
    };
    await setMeta('sync', { ...previous, ...patch });
}

function stateAfter(stop: StepResult | null, lastError: OutboxError | null): SyncState {
    if (stop === 'offline' || stop === 'reauth') {
        return stop;
    }
    return lastError === null ? 'idle' : 'error';
}

function reportStop(stop: StepResult | null): void {
    if (stop === 'reauth') {
        paused = true;
        reportAuthError(new ReauthRequiredError('Please sign in again.'));
    } else if (stop === 'wiped') {
        reportAuthError(new DeviceWipedError('This device was signed out and its data removed.'));
    }
}

async function processAll(ctx: RunContext): Promise<{ stop: StepResult | null; blocked: number }> {
    const ops = await opsOfUser(ctx.userId);
    const uuids = [...new Set(ops.map((op) => op.submissionUuid))];
    let blocked = 0;
    for (const uuid of uuids) {
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

async function runOnce(manual: boolean): Promise<SyncRunResult> {
    const userId = getCurrentUserId();
    const lastRunAt = Date.now();
    if (userId === null || !navigator.onLine) {
        const state: SyncState = userId === null ? 'idle' : 'offline';
        await writeSyncMeta({ state, lastRunAt });
        const remaining = userId === null ? 0 : (await opsOfUser(userId)).length;
        return { ran: true, sent: 0, blocked: 0, remaining, state };
    }
    await writeSyncMeta({ state: 'running', lastRunAt });
    const ctx: RunContext = { manual, now: lastRunAt, userId, sent: 0 };
    const { stop, blocked } = await processAll(ctx);
    reportStop(stop);
    if (stop === 'wiped') {
        return { ran: true, sent: ctx.sent, blocked, remaining: 0, state: 'idle' };
    }
    const left = await opsOfUser(userId);
    const lastError = left.find((op) => op.lastError !== null)?.lastError ?? null;
    const state = stateAfter(stop, lastError);
    await writeSyncMeta({
        state,
        lastError,
        ...(stop === null ? { lastSuccessAt: Date.now() } : {}),
    });
    return { ran: true, sent: ctx.sent, blocked, remaining: left.length, state };
}

/** Wakes the engine when the earliest backoff ends (the 60 s interval is only a fallback). */
async function scheduleWake(): Promise<void> {
    const userId = getCurrentUserId();
    if (!started || paused || userId === null) {
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
    if (Number.isFinite(next)) {
        wakeTimer = setTimeout(() => void runSync(), next - now);
    }
}

async function execute(manual: boolean): Promise<SyncRunResult> {
    const result = await withSyncLock(() => runOnce(manual));
    if (result === null) {
        // Another tab holds the lock and syncs the same outbox.
        return { ran: false, sent: 0, blocked: 0, remaining: 0, state: 'running' };
    }
    await scheduleWake();
    return result;
}

/** Runs the outbox now. During a run in this tab, one follow-up run is queued and returned. */
export function runSync(options: { readonly manual?: boolean } = {}): Promise<SyncRunResult> {
    const manual = options.manual === true;
    if (active !== null) {
        rerunManual ||= manual;
        rerun ??= active.then(() => {
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

function onRequest(reason: SyncReason): void {
    if (RESUMING_REASONS.has(reason)) {
        paused = false;
    } else if (paused) {
        return;
    }
    if (reason === 'enqueue') {
        if (enqueueTimer !== null) {
            clearTimeout(enqueueTimer);
        }
        enqueueTimer = setTimeout(() => {
            enqueueTimer = null;
            void runSync();
        }, ENQUEUE_DELAY_MS);
        return;
    }
    void runSync({ manual: MANUAL_REASONS.has(reason) });
}

async function onInterval(): Promise<void> {
    const userId = getCurrentUserId();
    if (paused || userId === null || document.visibilityState !== 'visible') {
        return;
    }
    if ((await opsOfUser(userId)).length > 0) {
        void runSync();
    }
}

/** Wires the triggers (requests from every tab, online, visible, interval) and returns a stop function. */
export function startSyncEngine(): () => void {
    started = true;
    const stopRequests = onSyncRequested(onRequest);
    const onOnline = (): void => {
        onRequest('online');
    };
    const onVisibility = (): void => {
        if (document.visibilityState === 'visible') {
            onRequest('visible');
        }
    };
    window.addEventListener('online', onOnline);
    document.addEventListener('visibilitychange', onVisibility);
    const interval = setInterval(() => void onInterval(), INTERVAL_MS);
    onRequest('start');
    return () => {
        started = false;
        stopRequests();
        window.removeEventListener('online', onOnline);
        document.removeEventListener('visibilitychange', onVisibility);
        clearInterval(interval);
        for (const timer of [enqueueTimer, wakeTimer]) {
            if (timer !== null) {
                clearTimeout(timer);
            }
        }
        enqueueTimer = null;
        wakeTimer = null;
    };
}

/** True while a lost session holds automatic syncing back. */
export function isSyncPaused(): boolean {
    return paused;
}

/** Tests only: forget the pause and any run in flight. */
export function resetSyncEngineForTests(): void {
    paused = false;
    active = null;
    rerun = null;
    rerunManual = false;
}
