/** Runs home-realm discovery for an email (ADR 0036) with a busy flag and a localized error. */
import { useState } from 'react';
import { useT } from '../../i18n/I18nProvider.tsx';
import { discover } from '../../lib/api/endpoints.ts';
import type { DiscoveryResult } from '../../lib/api/types.ts';
import { discoveryErrorMessage } from './errors.ts';

export type DiscoveryHandler = (email: string, result: DiscoveryResult) => void;

export interface Discovery {
    readonly busy: boolean;
    readonly error: string | null;
    readonly run: (email: string) => Promise<void>;
}

export function useDiscovery(onResult: DiscoveryHandler): Discovery {
    const t = useT();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const run = async (email: string): Promise<void> => {
        const trimmed = email.trim();
        setBusy(true);
        setError(null);
        try {
            const result = await discover(trimmed);
            setBusy(false);
            onResult(trimmed, result);
        } catch (caught) {
            setError(discoveryErrorMessage(caught, t));
            setBusy(false);
        }
    };

    return { busy, error, run };
}
