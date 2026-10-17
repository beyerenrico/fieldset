/** React binding for Dexie liveQuery (ADR 0035). */
import { liveQuery } from 'dexie';
import { useEffect, useState } from 'react';

/** Re-runs `query` whenever the tables it read change, also from other tabs. */
export function useLiveQuery<T>(query: () => Promise<T>, deps: readonly unknown[]): T | undefined {
    const [value, setValue] = useState<T | undefined>(undefined);
    useEffect(() => {
        const subscription = liveQuery(query).subscribe({
            next: (next) => {
                setValue(() => next);
            },
            error: (error: unknown) => {
                console.error('liveQuery failed', error);
            },
        });
        return () => {
            subscription.unsubscribe();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, deps);
    return value;
}
