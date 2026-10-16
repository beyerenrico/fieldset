/**
 * Debounced draft autosave (§9.2): never two saves in flight, the latest data wins, revisions are
 * tracked, conflicts/locks stop saving until resolved, network failures retry with backoff.
 */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import { ApiError, NetworkError, isAuthLost, isConflict } from '../api/errors.ts';
import type { Submission } from '../api/types.ts';
import type { AutosaveOptions, AutosaveSnapshot, SaveState } from './types.ts';

const DEBOUNCE_MS = 1500;
const RETRY_MS = 5000;
const MAX_RETRY_MS = 60000;

function serverCopy(error: ApiError): Submission | null {
    const details = error.details;
    if (typeof details !== 'object' || details === null || !('submission' in details)) {
        return null;
    }
    const submission = details.submission;
    return typeof submission === 'object' && submission !== null
        ? (submission as Submission)
        : null;
}

export class AutosaveController {
    private snapshot: AutosaveSnapshot;
    private current: JsonObject;
    private dirty = false;
    private inflight: Promise<void> | null = null;
    private timer: ReturnType<typeof setTimeout> | null = null;
    private retryTimer: ReturnType<typeof setTimeout> | null = null;
    private retryDelay: number;
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

    /** Latest data handed to the controller. */
    get data(): JsonObject {
        return this.current;
    }

    update(data: JsonObject): void {
        this.current = data;
        this.dirty = true;
        if (this.blocked()) {
            return;
        }
        if (this.snapshot.state !== 'saving') {
            this.set({ state: 'pending' });
        }
        this.schedule(this.options.debounceMs ?? DEBOUNCE_MS);
    }

    /** Saves now and waits; `ensureCreated` forces a PUT when the draft does not exist yet. */
    async flush(options: { ensureCreated?: boolean } = {}): Promise<AutosaveSnapshot> {
        if (options.ensureCreated === true && this.snapshot.revision === 0) {
            this.dirty = true;
        }
        for (;;) {
            this.clearTimer();
            if (this.inflight !== null) {
                await this.inflight;
                continue;
            }
            if (!this.dirty || this.blocked()) {
                break;
            }
            await this.run();
            if (this.failed()) {
                break;
            }
        }
        return this.snapshot;
    }

    /** "Keep mine" re-saves the local data on top of the server revision; "server" adopts it. */
    resolveConflict(choice: 'mine' | 'server'): JsonObject {
        const server = this.snapshot.conflict;
        if (server === null) {
            return this.current;
        }
        if (choice === 'server') {
            this.current = server.data;
            this.dirty = false;
            this.set({
                state: 'saved',
                revision: server.revision,
                submission: server,
                conflict: null,
                error: null,
            });
            return server.data;
        }
        this.dirty = true;
        this.set({ state: 'pending', revision: server.revision, conflict: null, error: null });
        void this.run();
        return this.current;
    }

    /** A conflict detected elsewhere (e.g. on submit). */
    reportConflict(server: Submission): void {
        this.set({ state: 'conflict', conflict: server });
    }

    /** Adopts a newer server copy (e.g. after submit). */
    acceptServer(submission: Submission): void {
        this.set({ revision: submission.revision, submission });
    }

    start(): void {
        window.addEventListener('online', this.onOnline);
        document.addEventListener('visibilitychange', this.onVisibility);
    }

    stop(): void {
        window.removeEventListener('online', this.onOnline);
        document.removeEventListener('visibilitychange', this.onVisibility);
        this.clearRetry();
        void this.flush();
    }

    private readonly onOnline = (): void => {
        if (this.snapshot.state === 'offline') {
            this.clearRetry();
            void this.run();
        }
    };

    private readonly onVisibility = (): void => {
        if (document.visibilityState === 'hidden') {
            void this.flush();
        }
    };

    private run(): Promise<void> {
        if (this.inflight !== null) {
            return this.inflight;
        }
        if (!this.dirty || this.blocked()) {
            return Promise.resolve();
        }
        const data = this.current;
        this.dirty = false;
        this.clearRetry();
        this.set({ state: 'saving' });
        const { uuid, formVersionId, transport } = this.options;
        this.inflight = transport
            .save({ uuid, formVersionId, data, revision: this.snapshot.revision })
            .then(
                (submission) => {
                    this.retryDelay = this.options.retryMs ?? RETRY_MS;
                    this.set({
                        state: this.dirty ? 'pending' : 'saved',
                        revision: submission.revision,
                        submission,
                        error: null,
                        savedAt: Date.now(),
                    });
                },
                (error: unknown) => {
                    this.fail(error);
                },
            )
            .finally(() => {
                this.inflight = null;
                if (this.dirty && this.snapshot.state === 'pending' && this.timer === null) {
                    this.schedule(0);
                }
            });
        return this.inflight;
    }

    private fail(error: unknown): void {
        this.dirty = true;
        if (error instanceof NetworkError) {
            this.set({ state: 'offline', error });
            this.scheduleRetry();
        } else if (isConflict(error, 'revision_conflict') && serverCopy(error) !== null) {
            this.set({ state: 'conflict', conflict: serverCopy(error), error });
        } else if (isConflict(error, 'not_editable')) {
            this.set({ state: 'locked', error });
        } else {
            this.set({ state: 'error', error });
            if (isAuthLost(error)) {
                this.options.onAuthLost?.(error);
            } else if (error instanceof ApiError && error.status >= 500) {
                this.scheduleRetry();
            }
        }
    }

    private blocked(): boolean {
        return this.snapshot.state === 'conflict' || this.snapshot.state === 'locked';
    }

    private failed(): boolean {
        const failures: readonly SaveState[] = ['offline', 'error', 'conflict', 'locked'];
        return failures.includes(this.snapshot.state);
    }

    private schedule(ms: number): void {
        this.clearTimer();
        this.timer = setTimeout(() => {
            this.timer = null;
            void this.run();
        }, ms);
    }

    private scheduleRetry(): void {
        this.clearRetry();
        const delay = this.retryDelay;
        this.retryDelay = Math.min(delay * 2, MAX_RETRY_MS);
        this.retryTimer = setTimeout(() => {
            this.retryTimer = null;
            void this.run();
        }, delay);
    }

    private clearTimer(): void {
        if (this.timer !== null) {
            clearTimeout(this.timer);
            this.timer = null;
        }
    }

    private clearRetry(): void {
        if (this.retryTimer !== null) {
            clearTimeout(this.retryTimer);
            this.retryTimer = null;
        }
    }

    private set(patch: Partial<AutosaveSnapshot>): void {
        this.snapshot = { ...this.snapshot, ...patch };
        for (const listener of this.listeners) {
            listener();
        }
    }
}
