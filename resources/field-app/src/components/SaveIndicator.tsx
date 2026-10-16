import { Check, CloudOff, LoaderCircle, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useT } from '../i18n/I18nProvider.tsx';
import type { SaveState } from '../lib/autosave/types.ts';
import { cn } from '../lib/cn.ts';

function Icon({ state }: { readonly state: SaveState }): ReactNode {
    const props = { 'aria-hidden': true, className: 'size-4 shrink-0' } as const;
    switch (state) {
        case 'saving':
            return <LoaderCircle {...props} className="size-4 shrink-0 animate-spin" />;
        case 'saved':
            return <Check {...props} />;
        case 'offline':
            return <CloudOff {...props} />;
        case 'error':
        case 'conflict':
        case 'locked':
            return <TriangleAlert {...props} />;
        default:
            return null;
    }
}

/** States worth interrupting for; routine saving progress is only shown, never announced. */
const ANNOUNCED: ReadonlySet<SaveState> = new Set(['offline', 'error', 'conflict', 'locked']);

export function SaveIndicator({ state }: { readonly state: SaveState }): ReactNode {
    const t = useT();
    const problem = state === 'error' || state === 'conflict' || state === 'locked';
    const text = t(`save.${state}`);
    return (
        <>
            <p
                data-state={state}
                className={cn(
                    'inline-flex items-center gap-1 text-sm font-semibold',
                    problem ? 'text-destructive' : 'text-muted-foreground',
                )}
            >
                <Icon state={state} />
                {text}
            </p>
            <span role="status" className="sr-only">
                {ANNOUNCED.has(state) ? text : null}
            </span>
        </>
    );
}
