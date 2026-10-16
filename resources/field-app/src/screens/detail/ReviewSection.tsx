/** Review panel on the submission detail (§8.3, M4): approve or request changes on my open task. */
import { Check, MessageSquareWarning } from 'lucide-react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { Dialog } from '../../components/ui/dialog.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { Submission } from '../../lib/api/types.ts';
import type { TaskCompletionInput } from '../../lib/api/workflow.ts';
import { ApproveForm, RejectForm } from './DecisionForms.tsx';
import { useCompleteTask } from './useCompleteTask.ts';

type DialogKind = 'approve' | 'reject';

const FOCUS_RING =
    'outline-none focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ring';

interface PanelProps {
    readonly onOpen: (kind: DialogKind) => void;
}

function DecisionPanel({ onOpen }: PanelProps): ReactNode {
    const t = useT();
    const titleId = useId();
    return (
        <section
            aria-labelledby={titleId}
            className="flex flex-col gap-3 rounded-lg border-2 border-primary bg-primary/5 p-4"
        >
            <h2 id={titleId} className="text-lg font-bold">
                {t('decision.title')}
            </h2>
            <p>{t('decision.hint')}</p>
            <div className="flex flex-wrap gap-2">
                <Button
                    size="lg"
                    onClick={() => {
                        onOpen('approve');
                    }}
                >
                    <Check aria-hidden="true" />
                    {t('decision.approve')}
                </Button>
                <Button
                    size="lg"
                    variant="outline"
                    onClick={() => {
                        onOpen('reject');
                    }}
                >
                    <MessageSquareWarning aria-hidden="true" />
                    {t('decision.requestChanges')}
                </Button>
            </div>
        </section>
    );
}

export function ReviewSection({ submission }: { readonly submission: Submission }): ReactNode {
    const t = useT();
    const state = useCompleteTask(submission.uuid);
    const taskId = submission.open_task_id ?? null;
    const [dialog, setDialog] = useState<DialogKind | null>(null);
    /** The task the open dialog decides on (stays fixed while the submission refreshes). */
    const dialogTask = useRef<number | null>(null);
    /** Changes on every open and close: a late answer only acts on the dialog it was sent from. */
    const dialogRun = useRef(0);
    const focusAfterClose = useRef<'result' | 'problem' | null>(null);
    const resultMessage = useRef<HTMLDivElement>(null);
    const problemMessage = useRef<HTMLDivElement>(null);

    const open = (kind: DialogKind): void => {
        dialogRun.current += 1;
        dialogTask.current = taskId;
        state.clear();
        setDialog(kind);
    };
    const close = (): void => {
        dialogRun.current += 1;
        setDialog(null);
    };
    const cancel = (): void => {
        state.clear();
        close();
    };

    const decide = async (input: TaskCompletionInput): Promise<void> => {
        const run = dialogRun.current;
        const task = dialogTask.current;
        if (task === null) {
            return;
        }
        const outcome = await state.complete(task, input);
        if (outcome !== 'retry' && dialogRun.current === run) {
            focusAfterClose.current = outcome === 'done' ? 'result' : 'problem';
            close();
        }
    };

    // The page is inert while a dialog is open; once it has closed, focus carries the outcome.
    useEffect(() => {
        const target = focusAfterClose.current;
        if (dialog === null && target !== null) {
            focusAfterClose.current = null;
            (target === 'result' ? resultMessage : problemMessage).current?.focus();
        }
    }, [dialog]);

    return (
        <>
            {taskId === null ? null : <DecisionPanel onOpen={open} />}
            {/* Always mounted: announces the outcome, also after the panel disappeared. */}
            <div role="status">
                {state.result === null ? null : (
                    <Alert
                        ref={resultMessage}
                        tabIndex={-1}
                        variant="success"
                        className={FOCUS_RING}
                    >
                        {state.result === 'approved'
                            ? t('decision.approved')
                            : t('decision.changesRequested')}
                    </Alert>
                )}
            </div>
            {dialog === null && state.problem !== null ? (
                <Alert
                    ref={problemMessage}
                    tabIndex={-1}
                    variant="destructive"
                    role="alert"
                    className={FOCUS_RING}
                >
                    {t(state.problem)}
                </Alert>
            ) : null}
            <Dialog
                open={dialog === 'approve'}
                onClose={cancel}
                title={t('decision.approveTitle')}
                description={t('decision.approveDescription')}
            >
                <ApproveForm
                    busy={state.busy}
                    problem={state.problem}
                    onConfirm={() => void decide({ outcome: 'approve' })}
                    onCancel={cancel}
                />
            </Dialog>
            <Dialog
                open={dialog === 'reject'}
                onClose={cancel}
                title={t('decision.requestChangesTitle')}
                description={t('decision.requestChangesDescription')}
            >
                <RejectForm
                    busy={state.busy}
                    problem={state.problem}
                    commentError={state.commentError}
                    onEdit={state.clearCommentError}
                    onReject={(comment) => void decide({ outcome: 'reject', comment })}
                    onCancel={cancel}
                />
            </Dialog>
        </>
    );
}
