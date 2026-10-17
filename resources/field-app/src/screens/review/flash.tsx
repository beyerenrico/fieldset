/**
 * One-time confirmations after leaving a screen (Discard, Copy): passed to the target screen in
 * the history state and shown there as a status message.
 */
import { useLocation } from '@tanstack/react-router';
import { useEffect, useState, type ReactNode } from 'react';
import { Alert } from '../../components/ui/alert.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { MessageKey } from '../../i18n/en.ts';

export type Flash = 'discarded' | 'copied';

declare module '@tanstack/history' {
    interface HistoryState {
        readonly fsaFlash?: Flash;
    }
}

const TEXTS: Readonly<Record<Flash, MessageKey>> = {
    discarded: 'sync.discarded',
    copied: 'sync.copied',
};

/** The `state` of a navigation that confirms `flash` on the target screen. */
export const flashState = (flash: Flash): { readonly fsaFlash: Flash } => ({ fsaFlash: flash });

export function FlashNotice(): ReactNode {
    const t = useT();
    // History state is untrusted (it survives reloads): only the known values are shown.
    const flash = useLocation({ select: (location) => location.state.fsaFlash });
    const key = flash === 'discarded' || flash === 'copied' ? TEXTS[flash] : null;
    // The live region exists before its text arrives, so screen readers announce it.
    const [ready, setReady] = useState(false);
    useEffect(() => {
        const timer = setTimeout(() => {
            setReady(true);
        }, 0);
        return () => {
            clearTimeout(timer);
        };
    }, []);
    const shown = ready && key !== null;
    // Empty, the region stays in the accessibility tree but takes no space in the layout.
    return (
        <div role="status" className={shown ? undefined : 'sr-only'}>
            {shown ? <Alert variant="success">{t(key)}</Alert> : null}
        </div>
    );
}
