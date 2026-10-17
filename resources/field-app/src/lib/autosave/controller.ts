/**
 * Debounced local-first draft autosave (§9.2, ADR 0035): never two writes in flight, the latest
 * data wins, a failed write retries with backoff. Writes go to this device (the transport); the
 * sync engine sends them later. Pending edits are written when the page is hidden, frozen or
 * left. After `stop()`/`dispose()` nothing is written, scheduled or retried any more.
 */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import { watchPage } from './page-events.ts';
import { Timer } from './timer.ts';
import type {
    AutosaveOptions,
    AutosaveSnapshot,
    FlushOptions,
    SaveReceipt,
    SaveRequest,
} from './types.ts';

/** Two failures are the same when type, code and message match (a retry failing again). */
function sameFailure(previous: unknown, next: unknown): boolean {
    if (previous === next) {
        return true;
    }
    if (!(previous instanceof Error) || !(next instanceof Error)) {
        return false;
    }
    const codeOf = (error: Error): unknown => ('code' in error ? error.code : undefined);
    return (
        previous.constructor === next.constructor &&
        previous.message === next.message &&
        codeOf(previous) === codeOf(next)
    );
}

const DEBOUNCE_MS = 300;
const MAX_WAIT_MS = 2000;
const RETRY_MS = 1000;
const MAX_RETRY_MS = 30_000;

export class AutosaveController {
    private snapshot: AutosaveSnapshot;
    private current: JsonObject;
    private dirty = false;
    /** Write the row although nothing changed (ensureCreated before the first edit). */
    private ensure = false;
    /** Time of the oldest edit not written yet (maxWait). */
    private pendingSince: number | null = null;
    private inflight: Promise<void> | null = null;
    private readonly debounce = new Timer();
    private readonly retry = new Timer();
    private retryDelay: number;
    private unwatch: (() => void) | null = null;
    /** Set by stop()/dispose(): edits, timers and retries are ignored from then on. */
    private stopped = false;
    private readonly listeners = new Set<() => void>();
    private readonly options: AutosaveOptions;

    constructor(options: AutosaveOptions) {
        this.options = options;
        this.current = options.data;
        this.retryDelay = options.retryMs ?? RETRY_MS;
        const localVersion = options.localVersion ?? 0;
        this.snapshot = {
            // A restored draft is already saved on this device.
            state: localVersion > 0 ? 'saved' : 'idle',
            localVersion,
            error: null,
            savedAt: null,
        };
    }

    readonly getSnapshot = (): AutosaveSnapshot => this.snapshot;

    readonly subscribe = (listener: () => void): (() => void) => {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    };

    /** Latest data (listeners hear about every edit). */
    get data(): JsonObject {
        return this.current;
    }

    update(data: JsonObject): void {
        if (this.stopped) {
            return;
        }
        this.current = data;
        this.dirty = true;
        const now = Date.now();
        this.pendingSince ??= now;
        const debounce = this.options.debounceMs ?? DEBOUNCE_MS;
        const maxWait = this.options.maxWaitMs ?? MAX_WAIT_MS;
        this.schedule(Math.max(0, Math.min(debounce, maxWait - (now - this.pendingSince))));
        if (this.snapshot.state === 'saving') {
            this.notify();
        } else {
            this.set({ state: 'pending' });
        }
    }

    /** Writes now and waits (no-op after stop/dispose). */
    async flush(options: FlushOptions = {}): Promise<AutosaveSnapshot> {
        return this.stopped ? this.snapshot : this.flushNow(options);
    }

    /**
     * Replaces the data without writing it (the caller writes the row, e.g. "Use server version").
     * Pending edits are dropped; a write in flight lands first.
     */
    async replaceData(data: JsonObject): Promise<void> {
        this.debounce.clear();
        this.retry.clear();
        this.dirty = false;
        this.pendingSince = null;
        while (this.inflight !== null) {
            await this.inflight;
        }
        this.dirty = false;
        this.current = data;
        this.set({ state: 'saved', error: null });
    }

    start(): void {
        if (!this.stopped) {
            this.unwatch ??= watchPage(() => void this.flush());
        }
    }

    /** One final write; afterwards nothing is written any more. */
    async stop(): Promise<void> {
        if (this.stopped) {
            return;
        }
        this.stopped = true;
        this.detach();
        await this.flushNow({});
        this.detach();
    }

    /** Stops without writing (logout, wipe: the store is being cleared). */
    dispose(): void {
        this.stopped = true;
        this.detach();
        this.notify();
    }

    /** True while a write is in flight or scheduled (debounce or retry). */
    busy(): boolean {
        return this.inflight !== null || this.debounce.active || this.retry.active;
    }

    /** Resolves once no write is in flight or scheduled any more. */
    settled(): Promise<void> {
        if (!this.busy()) {
            return Promise.resolve();
        }
        return new Promise((resolve) => {
            const unsubscribe = this.subscribe(() => {
                if (!this.busy()) {
                    unsubscribe();
                    resolve();
                }
            });
        });
    }

    private async flushNow(options: FlushOptions): Promise<AutosaveSnapshot> {
        if (options.ensureCreated === true && this.snapshot.localVersion === 0) {
            this.ensure = true;
        }
        for (;;) {
            this.debounce.clear();
            if (this.inflight !== null) {
                await this.inflight;
                continue;
            }
            if (!this.dirty && !this.ensure) {
                break;
            }
            await this.run();
            if (this.snapshot.state === 'error') {
                break;
            }
        }
        return this.snapshot;
    }

    private run(): Promise<void> {
        if (this.inflight !== null) {
            return this.inflight;
        }
        const ensureOnly = !this.dirty && this.ensure;
        if (!this.dirty && !ensureOnly) {
            this.notify();
            return Promise.resolve();
        }
        const data = this.current;
        this.dirty = false;
        this.ensure = false;
        this.pendingSince = null;
        this.retry.clear();
        const { uuid, formVersionId, transport } = this.options;
        const request: SaveRequest = {
            uuid,
            formVersionId,
            data,
            ...(ensureOnly ? { ensureOnly: true } : {}),
        };
        this.inflight = transport.save(request).then(
            (receipt) => {
                this.inflight = null;
                this.saved(receipt);
            },
            (error: unknown) => {
                this.inflight = null;
                this.fail(error, ensureOnly);
            },
        );
        if (this.snapshot.state !== 'error') {
            this.set({ state: 'saving' }); // a retry keeps showing the error until it succeeds
        }
        return this.inflight;
    }

    private saved(receipt: SaveReceipt): void {
        this.retryDelay = this.options.retryMs ?? RETRY_MS;
        if (this.dirty && !this.debounce.active) {
            this.schedule(0); // edits arrived while writing
        }
        this.set({
            state: this.dirty ? 'pending' : 'saved',
            localVersion: receipt.localVersion,
            error: null,
            savedAt: Date.now(),
        });
    }

    /**
     * IndexedDB refused the write (quota, private mode, ended session, …): keep the data and retry.
     * The same failure again keeps the snapshot (and its error object) as it is: listeners hear
     * about a new error, not about every retry.
     */
    private fail(error: unknown, ensureOnly: boolean): void {
        if (ensureOnly) {
            this.ensure = true;
        } else {
            this.dirty = true;
            this.pendingSince ??= Date.now();
        }
        this.scheduleRetry();
        const previous = this.snapshot;
        if (previous.state === 'error' && sameFailure(previous.error, error)) {
            if (!this.busy()) {
                this.notify(); // no retry follows (stopped): waiters of settled() must hear it
            }
            return;
        }
        this.set({ state: 'error', error });
    }

    private schedule(ms: number): void {
        if (!this.stopped) {
            this.debounce.start(ms, () => void this.run());
        }
    }

    private scheduleRetry(): void {
        if (this.stopped) {
            return;
        }
        const delay = this.retryDelay;
        this.retryDelay = Math.min(delay * 2, MAX_RETRY_MS);
        this.retry.start(delay, () => void this.run());
    }

    /** Removes the page listeners and every timer. */
    private detach(): void {
        this.unwatch?.();
        this.unwatch = null;
        this.debounce.clear();
        this.retry.clear();
    }

    private set(patch: Partial<AutosaveSnapshot>): void {
        this.snapshot = { ...this.snapshot, ...patch };
        this.notify();
    }

    private notify(): void {
        for (const listener of this.listeners) {
            listener();
        }
    }
}
