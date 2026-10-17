/**
 * When to show a count of unsynced submissions (header badge, iOS warning). Online, every autosave
 * is pending for a moment, so a count that appeared at once would flicker: it shows offline (or
 * unreachable) at once, online only once it has been pending for PENDING_GRACE_MS with no sync run
 * in progress. Items that need a decision are not delayed (callers show them directly).
 */
import { useEffect, useState } from 'react';
import { useConnection, useSyncMeta } from './useSyncStatus.ts';

export const PENDING_GRACE_MS = 10_000;

export function usePendingVisible(count: number): boolean {
    const connection = useConnection();
    const running = useSyncMeta()?.state === 'running';
    const pending = count > 0;
    const [aged, setAged] = useState(false);

    useEffect(() => {
        if (!pending) {
            return undefined;
        }
        const timer = setTimeout(() => {
            setAged(true);
        }, PENDING_GRACE_MS);
        return () => {
            clearTimeout(timer);
            setAged(false);
        };
    }, [pending]);

    return pending && (connection !== 'online' || (aged && !running));
}
