/**
 * A draft the server did not accept (ADR 0035): what happened and the user's choices — "save as a
 * new draft" (not_editable), "copy to the current version" (version_retired), "try again", and
 * "discard" with a confirmation step.
 */
import { Link } from '@tanstack/react-router';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { Button } from '../../components/ui/button.tsx';
import type { DraftEditor } from '../../hooks/useDraftEditor.ts';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { MessageKey } from '../../i18n/en.ts';
import { useBlockedActions } from './useBlockedActions.ts';

const KNOWN: ReadonlySet<string> = new Set([
    'not_editable',
    'version_retired',
    'version_mismatch',
    'form_access_revoked',
    'forbidden',
    'not_found',
    'payload_too_large',
]);

function useBlockedText(code: string): string {
    const t = useT();
    return KNOWN.has(code)
        ? t(`sync.blocked.${code}` as MessageKey)
        : t('sync.blocked.other', { code });
}

function DiscardButton({
    onConfirm,
    busy,
}: {
    readonly onConfirm: () => void;
    readonly busy: boolean;
}): ReactNode {
    const t = useT();
    const [confirming, setConfirming] = useState(false);
    if (!confirming) {
        return (
            <Button
                variant="outline"
                disabled={busy}
                onClick={() => {
                    setConfirming(true);
                }}
            >
                {t('sync.action.discard')}
            </Button>
        );
    }
    return (
        <span
            className="flex flex-wrap items-center gap-2"
            role="group"
            aria-label={t('sync.action.discardConfirm')}
        >
            <span className="font-semibold">{t('sync.action.discardConfirm')}</span>
            <Button variant="destructive" disabled={busy} onClick={onConfirm}>
                {t('sync.action.discardYes')}
            </Button>
            <Button
                variant="outline"
                onClick={() => {
                    setConfirming(false);
                }}
            >
                {t('sync.action.cancel')}
            </Button>
        </span>
    );
}

export interface BlockedPanelProps {
    readonly editor: DraftEditor;
    readonly code: string;
    readonly formId: number;
}

export function BlockedPanel({ editor, code, formId }: BlockedPanelProps): ReactNode {
    const t = useT();
    const titleId = useId();
    const heading = useRef<HTMLHeadingElement>(null);
    const actions = useBlockedActions(editor, formId);
    const text = useBlockedText(code);
    const copyLabel =
        code === 'version_retired' ? t('sync.action.copyToCurrent') : t('sync.action.saveAsNew');

    useEffect(() => {
        heading.current?.focus();
    }, [code]);

    return (
        <section
            role="alert"
            aria-labelledby={titleId}
            className="rounded-lg border-2 border-destructive bg-card p-4"
        >
            <h2 id={titleId} ref={heading} tabIndex={-1} className="text-lg font-bold outline-none">
                {t('sync.blocked.title')}
            </h2>
            <p className="mt-1">{text}</p>
            <div className="mt-4 flex flex-wrap gap-2">
                {actions.copy === null ? null : (
                    <Button disabled={actions.busy} onClick={() => void actions.copy?.()}>
                        {copyLabel}
                    </Button>
                )}
                {code === 'not_editable' || code === 'version_retired' ? null : (
                    <Button disabled={actions.busy} onClick={() => void actions.retry()}>
                        {t('sync.action.retry')}
                    </Button>
                )}
                {code === 'not_editable' ? (
                    <Button asChild variant="outline">
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
