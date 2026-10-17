import { Check, CloudOff, CloudUpload, LoaderCircle, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useT } from '../i18n/I18nProvider.tsx';
import type { SaveState } from '../lib/autosave/types.ts';
import type { DraftSync } from '../lib/db/schema.ts';
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
const PROBLEMS: ReadonlySet<SaveState> = new Set(['error', 'conflict', 'locked']);
/** Sync states shown next to "Saved on this device" (conflict/blocked have their own panel). */
const SHOWN_SYNC: ReadonlySet<DraftSync> = new Set(['queued', 'syncing', 'synced']);

export interface SaveIndicatorProps {
    /** Local save state: `saved` means saved on this device (ADR 0035). */
    readonly state: SaveState;
    /** Sync state of the draft's row; null/absent: not shown. */
    readonly sync?: DraftSync | null;
}

export function SaveIndicator({ state, sync = null }: SaveIndicatorProps): ReactNode {
    const t = useT();
    const problem = PROBLEMS.has(state);
    const text = state === 'saved' ? t('sync.savedLocal') : t(`save.${state}`);
    const syncText =
        !problem && sync !== null && SHOWN_SYNC.has(sync) ? t(`sync.state.${sync}`) : null;
    return (
        <>
            <p
                data-state={state}
                data-sync={sync ?? undefined}
                className={cn(
                    'inline-flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold',
                    problem ? 'text-destructive' : 'text-muted-foreground',
                )}
            >
                <span className="inline-flex items-center gap-1">
                    <Icon state={state} />
                    {text}
                </span>
                {syncText === null ? null : (
                    <span data-testid="sync-state" className="inline-flex items-center gap-1">
                        {sync === 'synced' ? null : (
                            <CloudUpload aria-hidden="true" className="size-4 shrink-0" />
                        )}
                        {syncText}
                    </span>
                )}
            </p>
            <span role="status" className="sr-only">
                {ANNOUNCED.has(state) ? text : null}
            </span>
        </>
    );
}
