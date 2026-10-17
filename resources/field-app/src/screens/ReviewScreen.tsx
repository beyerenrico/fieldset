import { FormRenderer } from '@form-schema-app/form-schema-react';
import { getRouteApi, Link } from '@tanstack/react-router';
import type { ReactNode } from 'react';
import { ConflictPanel } from '../components/ConflictPanel.tsx';
import { SaveIndicator } from '../components/SaveIndicator.tsx';
import { UploadsHint } from '../components/UploadsHint.tsx';
import { Alert } from '../components/ui/alert.tsx';
import { Button } from '../components/ui/button.tsx';
import { useDraftEditor } from '../hooks/useDraftEditor.ts';
import { useI18n } from '../i18n/I18nProvider.tsx';
import { DraftLoader } from './DraftLoader.tsx';
import type { DraftFormProps } from './FillScreen.tsx';
import { BlockedPanel } from './review/BlockedPanel.tsx';
import { QueuedSubmitNote } from './review/QueuedSubmitNote.tsx';
import { useSubmit } from './review/useSubmit.ts';

const route = getRouteApi('/_auth/fill/$uuid/review');

function ReviewForm({ uuid, scope, start, version }: DraftFormProps): ReactNode {
    const { t, language } = useI18n();
    const editor = useDraftEditor(uuid, scope, start, version);
    const current = editor.server;
    /** Changes were requested: submitting again keeps the number (§6.5). */
    const resubmit = current?.status === 'changes_requested';
    const { busy, problem, showErrors, submit } = useSubmit(uuid, version, editor, resubmit);
    const uploading = editor.pendingWork > 0;
    const submitLabel = resubmit ? t('review.resubmit') : t('review.submit');
    const busyLabel = resubmit ? t('review.resubmitting') : t('review.submitting');

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <p className="text-muted-foreground">{version.definition.title}</p>
                    <h1 className="text-2xl font-bold">{t('review.title')}</h1>
                </div>
                <SaveIndicator state={editor.saveState} sync={editor.sync} />
            </div>
            {resubmit && current.number !== null ? (
                <p className="text-muted-foreground">
                    {t('review.numberKept', { number: current.number })}
                </p>
            ) : null}
            <Button asChild variant="outline" className="self-start">
                <Link to="/fill/$uuid" params={{ uuid }}>
                    {t('review.backToForm')}
                </Link>
            </Button>
            <QueuedSubmitNote editor={editor} />
            {editor.blockedBy === null ? null : (
                <BlockedPanel editor={editor} code={editor.blockedBy} formId={start.formId} />
            )}
            {editor.conflict === null ? null : (
                <ConflictPanel
                    definition={version.definition}
                    mine={editor.data}
                    server={editor.conflict.data}
                    onChoose={(choice) => void editor.resolveConflict(choice)}
                />
            )}
            {problem === null ? null : (
                <Alert variant="destructive" role="alert">
                    {t(problem)}
                </Alert>
            )}
            <UploadsHint visible={uploading} />
            <FormRenderer
                definition={version.definition}
                data={editor.data}
                context={editor.context}
                onChange={editor.onChange}
                errors={editor.serverErrors}
                attachments={editor.attachments}
                uiLocale={language}
                layout="scroll"
                readOnly
                readOnlyExcept={['signature']}
                showErrors={showErrors || editor.serverErrors.length > 0}
                onAsyncWork={editor.onAsyncWork}
                primaryAction={{
                    label: busy ? busyLabel : submitLabel,
                    onClick: () => void submit(),
                    busy,
                    disabled:
                        busy || uploading || editor.conflict !== null || editor.blockedBy !== null,
                }}
            />
        </div>
    );
}

export function ReviewScreen(): ReactNode {
    const { uuid } = route.useParams();
    return (
        <DraftLoader uuid={uuid}>
            {(start, version, scope) => (
                <ReviewForm key={uuid} uuid={uuid} scope={scope} start={start} version={version} />
            )}
        </DraftLoader>
    );
}
