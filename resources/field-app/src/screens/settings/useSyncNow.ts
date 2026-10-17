/** "Sync now" (§9.1 Settings, ADR 0035): a manual run with a result message for the user. */
import { useCallback, useState } from 'react';
import type { MessageKey, Translate } from '../../i18n/I18nProvider.tsx';
import { runSync, type SyncRunResult } from '../../lib/sync/engine.ts';
import { requestSync } from '../../lib/sync/triggers.ts';

export interface SyncNow {
    readonly run: () => Promise<void>;
    readonly running: boolean;
    /** Text of the last run's outcome; null before the first run. */
    readonly message: string | null;
}

type Outcome = readonly [MessageKey, Readonly<Record<string, number>>?];

function outcomeOf(result: SyncRunResult): Outcome {
    if (result.state === 'offline' || (!result.ran && !navigator.onLine)) {
        return ['account.syncOffline'];
    }
    if (result.state === 'reauth') {
        return ['account.syncReauth'];
    }
    if (!result.ran) {
        return ['account.syncBusy'];
    }
    if (result.state === 'error') {
        return ['account.syncError'];
    }
    if (result.blocked > 0) {
        return ['account.syncBlocked', { count: result.blocked }];
    }
    if (result.remaining > 0) {
        // `remaining` counts outbox operations, not submissions: no number for the user.
        return ['account.syncRemaining'];
    }
    return ['account.syncDone'];
}

export function syncMessage(result: SyncRunResult, t: Translate): string {
    const [key, params] = outcomeOf(result);
    return t(key, params);
}

export function useSyncNow(t: Translate): SyncNow {
    const [running, setRunning] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    const run = useCallback(async (): Promise<void> => {
        setRunning(true);
        setMessage(null);
        try {
            // The direct run reports this tab's result; the request resets backoff elsewhere (other
            // tabs, catalog refresh). A run already holding the lock makes the second one a no-op.
            const result = runSync({ manual: true });
            requestSync('manual');
            setMessage(syncMessage(await result, t));
        } catch {
            setMessage(t('account.syncError'));
        } finally {
            setRunning(false);
        }
    }, [t]);

    return { run, running, message };
}
