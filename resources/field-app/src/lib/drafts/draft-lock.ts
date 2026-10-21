/**
 * One editing tab per draft (ADR 0035): the tab that opens a draft holds the Web Lock
 * `fieldset-draft:<uuid>` until its session ends. A tab that finds the lock taken opens the draft
 * read-only and writes nothing. Without Web Locks there is no lock: the last writer wins.
 */
import { lockManager, type LockManagerLike } from '../sync/lock.ts';

/** `pending` until the browser answered; `unsupported`: no Web Locks (or the request failed). */
export type DraftLockState = 'pending' | 'held' | 'locked' | 'unsupported';

export interface DraftLock {
    readonly getState: () => DraftLockState;
    readonly subscribe: (listener: () => void) => () => void;
    /** Resolves once the lock was decided. */
    readonly ready: Promise<DraftLockState>;
    /** Gives the lock up (the session ended); safe to call more than once. */
    readonly release: () => void;
}

export const draftLockName = (uuid: string): string => `fieldset-draft:${uuid}`;

class WebDraftLock implements DraftLock {
    private state: DraftLockState;
    private released = false;
    private letGo: () => void = () => undefined;
    private readonly listeners = new Set<() => void>();
    readonly ready: Promise<DraftLockState>;

    constructor(uuid: string, locks: LockManagerLike | null) {
        this.state = locks === null ? 'unsupported' : 'pending';
        this.ready = locks === null ? Promise.resolve(this.state) : this.request(uuid, locks);
    }

    readonly getState = (): DraftLockState => this.state;

    readonly subscribe = (listener: () => void): (() => void) => {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    };

    readonly release = (): void => {
        this.released = true;
        this.letGo();
    };

    private request(uuid: string, locks: LockManagerLike): Promise<DraftLockState> {
        return new Promise((resolve) => {
            locks
                .request(draftLockName(uuid), { ifAvailable: true }, (lock) => {
                    const held = this.hold(lock);
                    resolve(this.state);
                    return held;
                })
                .catch((error: unknown) => {
                    console.warn('The draft lock could not be requested', error);
                    resolve(this.decide('unsupported'));
                });
        });
    }

    /** The lock callback: null means another tab holds it; else it is held until release(). */
    private hold(lock: unknown): Promise<void> {
        if (lock === null) {
            this.decide('locked');
            return Promise.resolve();
        }
        this.decide('held');
        if (this.released) {
            return Promise.resolve();
        }
        return new Promise((resolve) => {
            this.letGo = resolve;
        });
    }

    private decide(next: DraftLockState): DraftLockState {
        this.state = next;
        for (const listener of this.listeners) {
            listener();
        }
        return next;
    }
}

export function acquireDraftLock(uuid: string): DraftLock {
    return new WebDraftLock(uuid, lockManager());
}
