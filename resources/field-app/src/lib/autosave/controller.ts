/**
 * Debounced draft autosave (§9.2): never two saves in flight, the latest data wins, revisions are
 * tracked, conflicts/locks stop saving until resolved, network failures retry with backoff. After
 * `stop()`/`dispose()` or a lost session nothing is saved, scheduled or retried any more.
 */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import { ApiError, NetworkError, isAuthLost, isConflict } from '../api/errors.ts';
import type { Submission } from '../api/types.ts';
import { sameJson } from './same-json.ts';
import { serverCopy } from './server-copy.ts';
import { watchPage } from './page-events.ts';
import { Timer } from './timer.ts';
import type {
    AutosaveOptions,
    AutosaveSnapshot,
    FlushOptions,
    SaveRequest,
    SaveState,
} from './types.ts';

const DEBOUNCE_MS = 1500;
const RETRY_MS = 5000;
const MAX_RETRY_MS = 60000;
const FAILURES: readonly SaveState[] = ['offline', 'error', 'conflict', 'locked'];

export class AutosaveController {
    private snapshot: AutosaveSnapshot;
    private current: JsonObject;
    private dirty = false;
    private inflight: Promise<void> | null = null;
    private readonly debounce = new Timer();
    private unwatch: (() => void) | null = null;
    private readonly retry = new Timer();
    private retryDelay: number;
    /** Set by stop()/dispose(): edits, timers and retries are ignored from then on. */
    private stopped = false;
    /** The last save lost the session (reauth/wipe): a final save would only fail again. */
    private authLost = false;
    private readonly listeners = new Set<() => void>();
    private readonly options: AutosaveOptions;

    constructor(options: AutosaveOptions) {
        this.options = options;
        this.current = options.data;
        this.retryDelay = options.retryMs ?? RETRY_MS;
        this.snapshot = {
            state: 'idle',
            revision: options.revision,
            submission: null,
            conflict: null,
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

    /** True after a save failed because the session is gone (until a later save succeeds). */
    get sessionLost(): boolean {
        return this.authLost;
    }

    update(data: JsonObject): void {
        if (this.stopped) {
            return;
        }
        this.current = data;
        this.dirty = true;
        if (this.blocked()) {
            this.notify();
            return;
        }
        this.schedule(this.options.debounceMs ?? DEBOUNCE_MS);
        if (this.snapshot.state === 'saving') {
            this.notify();
        } else {
            this.set({ state: 'pending' });
        }
    }

    /** Saves now and waits (no-op after stop/dispose). */
    async flush(options: FlushOptions = {}): Promise<AutosaveSnapshot> {
        return this.stopped ? this.snapshot : this.flushNow(options);
    }

    /** "Keep mine" re-saves the local data on top of the server revision; "server" adopts it. */
    resolveConflict(choice: 'mine' | 'server'): JsonObject {
        const server = this.snapshot.conflict;
        if (server === null || this.stopped) {
            return this.current;
        }
        if (choice === 'server') {
            this.current = server.data;
            this.adopt(server);
            return server.data;
        }
        this.dirty = true;
        this.set({ state: 'pending', revision: server.revision, conflict: null, error: null });
        void this.run(false);
        return this.current;
    }

    /** A conflict detected elsewhere (e.g. on submit); returns false when the copy was adopted. */
    reportConflict(server: Submission): boolean {
        if (sameJson(server.data, this.current)) {
            this.adopt(server);
            return false;
        }
        this.set({ state: 'conflict', conflict: server });
        return true;
    }

    /** Adopts a newer server copy (e.g. after submit). */
    acceptServer(submission: Submission): void {
        this.set({ revision: submission.revision, submission });
    }

    start(): void {
        if (!this.stopped) {
            this.unwatch ??= watchPage(this.onOnline, () => void this.flush({ keepalive: true }));
        }
    }

    /** One final save (skipped after a lost session); afterwards nothing is saved any more. */
    async stop(): Promise<void> {
        if (this.stopped) {
            return;
        }
        this.stopped = true;
        this.detach();
        if (!this.authLost) {
            await this.flushNow({});
        }
        this.detach();
    }

    /** Stops without saving (logout, wipe, lost session). */
    dispose(): void {
        this.stopped = true;
        this.detach();
        this.notify();
    }

    /** True while a save is in flight or scheduled (debounce or retry). */
    busy(): boolean {
        return this.inflight !== null || this.debounce.active || this.retry.active;
    }

    /** Resolves once no save is in flight or scheduled any more. */
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

    private readonly onOnline = (): void => {
        if (this.snapshot.state === 'offline') {
            this.retry.clear();
            void this.run(false);
        }
    };

    private async flushNow(options: FlushOptions): Promise<AutosaveSnapshot> {
        if (options.ensureCreated === true && this.snapshot.revision === 0) {
            this.dirty = true;
        }
        for (;;) {
            this.debounce.clear();
            if (this.inflight !== null) {
                await this.inflight;
                continue;
            }
            if (!this.canSave()) {
                break;
            }
            await this.run(options.keepalive === true);
            if (this.failed()) {
                break;
            }
        }
        return this.snapshot;
    }

    private run(keepalive: boolean): Promise<void> {
        if (this.inflight !== null) {
            return this.inflight;
        }
        if (!this.canSave()) {
            this.notify();
            return Promise.resolve();
        }
        const data = this.current;
        this.dirty = false;
        this.retry.clear();
        const { uuid, formVersionId, transport } = this.options;
        const request: SaveRequest = {
            uuid,
            formVersionId,
            data,
            revision: this.snapshot.revision,
            ...(keepalive ? { keepalive: true } : {}),
        };
        this.inflight = transport.save(request).then(
            (submission) => {
                this.inflight = null;
                this.saved(submission);
            },
            (error: unknown) => {
                this.inflight = null;
                this.fail(error, data);
            },
        );
        this.set({ state: 'saving' });
        return this.inflight;
    }

    private saved(submission: Submission): void {
        this.authLost = false;
        this.retryDelay = this.options.retryMs ?? RETRY_MS;
        this.options.onSaved?.(submission);
        this.continueIfDirty();
        this.set({
            state: this.dirty ? 'pending' : 'saved',
            revision: submission.revision,
            submission,
            error: null,
            savedAt: Date.now(),
        });
    }

    private fail(error: unknown, sent: JsonObject): void {
        const server = serverCopy(error);
        if (
            server !== null &&
            (sameJson(server.data, sent) || sameJson(server.data, this.current))
        ) {
            // Our earlier write committed but its response was lost: same data, newer revision.
            this.adopt(server);
            return;
        }
        this.dirty = true;
        if (error instanceof NetworkError) {
            this.scheduleRetry();
            this.set({ state: 'offline', error });
        } else if (server !== null) {
            this.set({ state: 'conflict', conflict: server, error });
        } else if (isConflict(error, 'not_editable')) {
            this.set({ state: 'locked', error });
        } else if (isAuthLost(error)) {
            this.authLost = true;
            this.set({ state: 'error', error });
            this.options.onAuthLost?.(error);
        } else {
            if (error instanceof ApiError && error.status >= 500) {
                this.scheduleRetry();
            }
            this.set({ state: 'error', error });
        }
    }

    private adopt(server: Submission): void {
        this.dirty = !sameJson(server.data, this.current);
        this.retryDelay = this.options.retryMs ?? RETRY_MS;
        this.options.onSaved?.(server);
        this.continueIfDirty();
        this.set({
            state: this.dirty ? 'pending' : 'saved',
            revision: server.revision,
            submission: server,
            conflict: null,
            error: null,
            savedAt: Date.now(),
        });
    }

    private continueIfDirty(): void {
        if (this.dirty && !this.blocked() && !this.debounce.active) {
            this.schedule(0);
        }
    }

    private canSave(): boolean {
        return this.dirty && !this.blocked();
    }

    private blocked(): boolean {
        return this.snapshot.state === 'conflict' || this.snapshot.state === 'locked';
    }

    private failed(): boolean {
        return FAILURES.includes(this.snapshot.state);
    }

    private schedule(ms: number): void {
        if (!this.stopped) {
            this.debounce.start(ms, () => void this.run(false));
        }
    }

    private scheduleRetry(): void {
        if (this.stopped) {
            return;
        }
        const delay = this.retryDelay;
        this.retryDelay = Math.min(delay * 2, MAX_RETRY_MS);
        this.retry.start(delay, () => void this.run(false));
    }

    /** Removes the event listeners and every timer. */
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
