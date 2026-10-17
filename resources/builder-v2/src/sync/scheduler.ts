/**
 * Autosave (ADR 0037): debounce 800 ms, at most 5 s between saves, one request in flight, the
 * latest state wins. `invalid` keeps the local copy and shows the errors; `conflict` stops
 * autosave until the user reloads or overwrites (force). Explicit saves and flushes before
 * publish / Preview PDF / export / switching builders go through the same queue.
 */
import type { BuilderWire, SyncResult, SyncState } from '../contract.ts';
import type { Definition } from '../store/tree.ts';

export const DEBOUNCE_MS = 800;
export const MAX_WAIT_MS = 5000;
export const RETRY_BASE_MS = 5000;
export const RETRY_MAX_MS = 60000;

export type FlushOutcome = 'ok' | 'invalid' | 'conflict' | 'error';

export interface SchedulerDeps {
    readonly wire: Pick<BuilderWire, 'sync' | 'save'>;
    readonly getDefinition: () => Definition;
    readonly onState: (state: SyncState) => void;
    /** Every server answer, with the definition it refers to (lint pointers resolve against it). */
    readonly onResult: (result: SyncResult, checked: Definition) => void;
    readonly onError?: (error: unknown) => void;
}

type Timer = ReturnType<typeof setTimeout>;

export class SyncScheduler {
    readonly #deps: SchedulerDeps;
    #checksum: string;
    #saved: Definition;
    /** The last definition the server refused as invalid (not resent until it changes). */
    #refused: Definition | null = null;
    #state: SyncState = 'idle';
    #inFlight: Promise<FlushOutcome> | null = null;
    #debounce: Timer | null = null;
    #maxWait: Timer | null = null;
    #retry: Timer | null = null;
    #failures = 0;

    constructor(deps: SchedulerDeps, checksum: string, saved: Definition) {
        this.#deps = deps;
        this.#checksum = checksum;
        this.#saved = saved;
    }

    get state(): SyncState {
        return this.#state;
    }

    get checksum(): string {
        return this.#checksum;
    }

    /** Unsaved local changes (or a request still running). */
    isDirty(): boolean {
        return this.#inFlight !== null || this.#deps.getDefinition() !== this.#saved;
    }

    #setState(state: SyncState): void {
        if (state !== this.#state) {
            this.#state = state;
            this.#deps.onState(state);
        }
    }

    #clearTimers(): void {
        for (const timer of [this.#debounce, this.#maxWait, this.#retry]) {
            if (timer !== null) {
                clearTimeout(timer);
            }
        }
        this.#debounce = null;
        this.#maxWait = null;
        this.#retry = null;
    }

    /** Call after every committed change of the working copy. */
    notifyChange(): void {
        if (this.#state === 'conflict' || this.#inFlight !== null) {
            return;
        }
        const definition = this.#deps.getDefinition();
        if (definition === this.#saved || definition === this.#refused) {
            this.#clearTimers();
            this.#setState(
                definition === this.#refused
                    ? 'invalid'
                    : this.#state === 'idle'
                      ? 'idle'
                      : 'saved',
            );
            return;
        }
        this.#setState('pending');
        if (this.#debounce !== null) {
            clearTimeout(this.#debounce);
        }
        this.#debounce = setTimeout(() => void this.#run('sync', false), DEBOUNCE_MS);
        this.#maxWait ??= setTimeout(() => void this.#run('sync', false), MAX_WAIT_MS);
    }

    /** Saves pending changes now and waits for the answer (before publish, export, leaving…). */
    async flush(): Promise<FlushOutcome> {
        if (this.#inFlight !== null) {
            await this.#inFlight;
        }
        if (this.#state === 'conflict') {
            return 'conflict';
        }
        const definition = this.#deps.getDefinition();
        if (definition === this.#refused) {
            return 'invalid';
        }
        return definition === this.#saved ? 'ok' : this.#run('sync', false);
    }

    /** Asks the server for the lint report of the stored draft (status `unchanged`). */
    async validate(): Promise<FlushOutcome> {
        if (this.#inFlight !== null) {
            return this.#inFlight;
        }
        return this.#run('sync', false, true);
    }

    /** The explicit, audited save (also when nothing changed since the last autosave). */
    async save(): Promise<FlushOutcome> {
        if (this.#inFlight !== null) {
            await this.#inFlight;
        }
        return this.#state === 'conflict' ? 'conflict' : this.#run('save', false);
    }

    /** Resolves a conflict by overwriting the stored draft with the local copy. */
    async overwrite(): Promise<FlushOutcome> {
        if (this.#inFlight !== null) {
            await this.#inFlight;
        }
        return this.#run('sync', true);
    }

    #run(kind: 'sync' | 'save', force: boolean, always = false): Promise<FlushOutcome> {
        if (this.#inFlight !== null) {
            return this.#inFlight;
        }
        this.#clearTimers();
        const definition = this.#deps.getDefinition();
        if (kind === 'sync' && !force && !always && definition === this.#saved) {
            this.#setState(this.#state === 'idle' ? 'idle' : 'saved');
            return Promise.resolve('ok');
        }
        this.#setState('saving');
        const request = this.#deps.wire[kind](JSON.stringify(definition), this.#checksum, force);
        const done = request.then(
            (result) => this.#handle(result, definition),
            (error: unknown) => this.#fail(error),
        );
        this.#inFlight = done.finally(() => {
            this.#inFlight = null;
            this.#afterRequest();
        });
        return this.#inFlight;
    }

    #handle(result: SyncResult, definition: Definition): FlushOutcome {
        this.#failures = 0;
        this.#deps.onResult(result, definition);
        if (result.status === 'saved' || result.status === 'unchanged') {
            this.#checksum = result.checksum;
            this.#saved = definition;
            this.#refused = null;
            this.#setState('saved');
            return 'ok';
        }
        this.#refused = result.status === 'invalid' ? definition : this.#refused;
        this.#setState(result.status);
        return result.status;
    }

    #fail(error: unknown): FlushOutcome {
        this.#failures += 1;
        this.#deps.onError?.(error);
        this.#setState('error');
        const delay = Math.min(RETRY_MAX_MS, RETRY_BASE_MS * 2 ** (this.#failures - 1));
        this.#retry = setTimeout(() => void this.#run('sync', false), delay);
        return 'error';
    }

    /** The latest state wins: changes made while saving are scheduled again. */
    #afterRequest(): void {
        const dirty = this.#deps.getDefinition() !== this.#saved;
        if (dirty && (this.#state === 'saved' || this.#state === 'invalid')) {
            this.notifyChange();
        }
    }

    destroy(): void {
        this.#clearTimers();
    }
}
