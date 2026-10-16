/** Asynchronous edits of one draft (uploads, signature, location) that saves must wait for. */

export interface WorkTracker {
    /** Registers work and returns the same promise (its rejection stays the caller's). */
    readonly track: <T>(work: Promise<T>) => Promise<T>;
    readonly getPending: () => number;
    readonly subscribe: (listener: () => void) => () => void;
    /** Resolves once nothing is pending; waits one more task so the results are written first. */
    readonly idle: () => Promise<void>;
}

function nextTask(): Promise<void> {
    return new Promise((resolve) => {
        setTimeout(resolve, 0);
    });
}

export function createWorkTracker(): WorkTracker {
    const pending = new Set<Promise<unknown>>();
    const listeners = new Set<() => void>();
    const notify = (): void => {
        for (const listener of listeners) {
            listener();
        }
    };
    return {
        track: <T>(work: Promise<T>): Promise<T> => {
            pending.add(work);
            notify();
            const done = (): void => {
                pending.delete(work);
                notify();
            };
            work.then(done, done);
            return work;
        },
        getPending: () => pending.size,
        subscribe: (listener) => {
            listeners.add(listener);
            return () => {
                listeners.delete(listener);
            };
        },
        idle: async () => {
            for (;;) {
                if (pending.size > 0) {
                    await Promise.allSettled([...pending]);
                    continue;
                }
                await nextTask();
                if (pending.size === 0) {
                    return;
                }
            }
        },
    };
}
