/**
 * Fill and review: the draft's sync situation above the form (ADR 0035) — a queued submit, a
 * refused submit to fix, a blocking answer or a conflict to decide (announced once).
 */
import type { RefObject, ReactNode } from 'react';
import { ConflictPanel } from '../../components/ConflictPanel.tsx';
import type { DraftEditor } from '../../hooks/useDraftEditor.ts';
import type { FormVersion } from '../../lib/api/types.ts';
import { useAttachmentProblems } from './attachment-problems.ts';
import { BlockedPanel } from './BlockedPanel.tsx';
import { DecisionStatus } from './DecisionStatus.tsx';
import { QueuedSubmitNote } from './QueuedSubmitNote.tsx';
import { RefusalNotice } from './RefusalNotice.tsx';
import { isFixable, refusalOf } from './refusal.ts';

const NO_UUIDS: readonly string[] = [];

/** A conflict or a blocking answer: the form cannot go on until the user decides. */
export function waitsForDecision(editor: DraftEditor): boolean {
    return editor.conflict !== null || (editor.blockedBy !== null && !isFixable(editor.blockedBy));
}

/** Read-only because another tab edits the draft (the server's not_editable has its panel). */
export function lockedElsewhere(editor: DraftEditor): boolean {
    const refused = editor.row?.sync === 'blocked' && editor.row.blockedBy === 'not_editable';
    return editor.saveState === 'locked' && !refused;
}

export interface SyncPanelsProps {
    readonly editor: DraftEditor;
    readonly version: FormVersion;
    readonly formId: number;
    /** The screen's h1: it receives focus after a conflict choice (the panel goes away). */
    readonly titleRef: RefObject<HTMLHeadingElement | null>;
    /** Focus the panel (after the user's own action, e.g. Submit). */
    readonly autoFocus?: boolean;
    readonly onAutoFocused?: () => void;
}

export function SyncPanels({
    editor,
    version,
    formId,
    titleRef,
    autoFocus = false,
    onAutoFocused,
}: SyncPanelsProps): ReactNode {
    const blockedUpload = editor.blockedBy === 'attachment_blocked';
    const problems = useAttachmentProblems(blockedUpload ? [editor.uuid] : NO_UUIDS, editor.scope);
    const attachment = problems.get(editor.uuid);
    const refusal = refusalOf(editor.row);
    const focus = { autoFocus, ...(onAutoFocused === undefined ? {} : { onAutoFocused }) };

    const choose = async (choice: 'mine' | 'server'): Promise<void> => {
        await editor.resolveConflict(choice);
        titleRef.current?.focus();
    };

    return (
        <>
            <QueuedSubmitNote editor={editor} />
            <DecisionStatus editor={editor} attachment={attachment} />
            {refusal === null ? null : (
                <RefusalNotice uuid={editor.uuid} refusal={refusal} attachment={attachment} />
            )}
            {editor.blockedBy === null || refusal !== null ? null : (
                <BlockedPanel editor={editor} code={editor.blockedBy} formId={formId} {...focus} />
            )}
            {editor.conflict === null ? null : (
                <ConflictPanel
                    definition={version.definition}
                    mine={editor.data}
                    server={editor.conflict.data}
                    onChoose={(choice) => void choose(choice)}
                    {...focus}
                />
            )}
        </>
    );
}
