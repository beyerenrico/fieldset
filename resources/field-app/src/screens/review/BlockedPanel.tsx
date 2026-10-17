/**
 * A draft the server did not accept (ADR 0035): what happened, the next step, and only the choices
 * that can help — "save as a new draft" (not_editable, version_mismatch, not_found), "copy to the
 * current version" (version_retired) or "try again" (one of them is the primary action), and
 * "discard" with a confirmation step.
 */
import { Link } from '@tanstack/react-router';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { Button } from '../../components/ui/button.tsx';
import type { DraftEditor } from '../../hooks/useDraftEditor.ts';
import { useT } from '../../i18n/I18nProvider.tsx';
import { PANEL_BUTTON, PanelButton } from './PanelButton.tsx';
import { blockedTextKey } from './refusal.ts';
import { useBlockedActions } from './useBlockedActions.ts';

interface DiscardButtonProps {
    readonly onConfirm: () => void;
    readonly busy: boolean;
}

/** Focus moves into the confirmation; Cancel returns it to the "Discard" button. */
function DiscardButton({ onConfirm, busy }: DiscardButtonProps): ReactNode {
    const t = useT();
    const [confirming, setConfirming] = useState(false);
    const trigger = useRef<HTMLButtonElement>(null);
    const group = useRef<HTMLSpanElement>(null);
    const returnFocus = useRef(false);

    useEffect(() => {
        if (confirming) {
            group.current?.focus();
        } else if (returnFocus.current) {
            returnFocus.current = false;
            trigger.current?.focus();
        }
    }, [confirming]);

    if (!confirming) {
        return (
            <PanelButton
                ref={trigger}
                variant="outline"
                busy={busy}
                onClick={() => {
                    setConfirming(true);
                }}
            >
                {t('sync.action.discard')}
            </PanelButton>
        );
    }
    return (
        <span
            ref={group}
            tabIndex={-1}
            className="flex flex-wrap items-center gap-2 rounded-md outline-none focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ring"
            role="group"
            aria-label={t('sync.action.discardConfirm')}
        >
            <span className="font-semibold">{t('sync.action.discardConfirm')}</span>
            <PanelButton variant="destructive" busy={busy} onClick={onConfirm}>
                {t('sync.action.discardYes')}
            </PanelButton>
            <PanelButton
                variant="outline"
                onClick={() => {
                    returnFocus.current = true;
                    setConfirming(false);
                }}
            >
                {t('sync.action.cancel')}
            </PanelButton>
        </span>
    );
}

export interface BlockedPanelProps {
    readonly editor: DraftEditor;
    readonly code: string;
    readonly formId: number;
    /** Moves focus to the heading (only after the user's own action, e.g. Submit). */
    readonly autoFocus?: boolean;
    readonly onAutoFocused?: () => void;
}

export function BlockedPanel({
    editor,
    code,
    formId,
    autoFocus = false,
    onAutoFocused,
}: BlockedPanelProps): ReactNode {
    const t = useT();
    const titleId = useId();
    const heading = useRef<HTMLHeadingElement>(null);
    const actions = useBlockedActions(editor, formId);
    const copyLabel =
        code === 'version_retired' ? t('sync.action.copyToCurrent') : t('sync.action.saveAsNew');

    useEffect(() => {
        if (autoFocus) {
            heading.current?.focus();
            onAutoFocused?.();
        }
    }, [autoFocus, onAutoFocused]);

    return (
        <section
            role="region"
            aria-labelledby={titleId}
            className="rounded-lg border-2 border-destructive bg-card p-4"
        >
            <h2 id={titleId} ref={heading} tabIndex={-1} className="text-lg font-bold outline-none">
                {t('sync.blocked.title')}
            </h2>
            <p className="mt-1">{t(blockedTextKey(code))}</p>
            {actions.problem === null ? null : (
                <p role="alert" className="mt-2 font-semibold text-destructive">
                    {t(actions.problem)}
                </p>
            )}
            <div className="mt-4 flex flex-wrap gap-2">
                {actions.copy === null ? null : (
                    <PanelButton busy={actions.busy} onClick={() => void actions.copy?.()}>
                        {copyLabel}
                    </PanelButton>
                )}
                {actions.retry === null ? null : (
                    <PanelButton
                        variant={actions.copy === null ? 'default' : 'outline'}
                        busy={actions.busy}
                        onClick={() => void actions.retry?.()}
                    >
                        {t('sync.action.retry')}
                    </PanelButton>
                )}
                {code === 'not_editable' ? (
                    <Button asChild variant="outline" className={PANEL_BUTTON}>
                        <Link to="/submissions/$uuid" params={{ uuid: editor.uuid }}>
                            {t('fill.openDetail')}
                        </Link>
                    </Button>
                ) : null}
                <DiscardButton busy={actions.busy} onConfirm={() => void actions.discard()} />
            </div>
        </section>
    );
}
