import { FormRenderer } from '@fieldset/fieldset-react';
import { getRouteApi, Link } from '@tanstack/react-router';
import { useRef, type ReactNode } from 'react';
import { SaveIndicator } from '../components/SaveIndicator.tsx';
import { UploadsHint } from '../components/UploadsHint.tsx';
import { Alert } from '../components/ui/alert.tsx';
import { Button } from '../components/ui/button.tsx';
import { useDraftEditor } from '../hooks/useDraftEditor.ts';
import { useI18n } from '../i18n/I18nProvider.tsx';
import { DraftLoader } from './DraftLoader.tsx';
import type { DraftFormProps } from './FillScreen.tsx';
import { lockedElsewhere, SyncPanels, waitsForDecision } from './review/SyncPanels.tsx';
import { useSubmit } from './review/useSubmit.ts';

const route = getRouteApi('/_auth/fill/$uuid/review');

function ReviewForm({ uuid, scope, start, version }: DraftFormProps): ReactNode {
    const { t, language } = useI18n();
    const editor = useDraftEditor(uuid, scope, start, version);
    const title = useRef<HTMLHeadingElement>(null);
    const current = editor.server;
    /** Changes were requested: submitting again keeps the number (§6.5). */
    const resubmit = current?.status === 'changes_requested';
    const submitting = useSubmit(uuid, version, editor, resubmit);
    const { busy, problem, showErrors, submit } = submitting;
    const uploading = editor.pendingWork > 0;
    const submitLabel = resubmit ? t('review.resubmit') : t('review.submit');
    const busyLabel = resubmit ? t('review.resubmitting') : t('review.submitting');

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <p className="text-muted-foreground">{version.definition.title}</p>
                    <h1 ref={title} tabIndex={-1} className="text-2xl font-bold outline-none">
                        {t('review.title')}
                    </h1>
                </div>
                <SaveIndicator
                    state={editor.saveState}
                    sync={editor.sync}
                    error={editor.snapshot.error}
                    lockedElsewhere={lockedElsewhere(editor)}
                />
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
            <SyncPanels
                editor={editor}
                version={version}
                formId={start.formId}
                titleRef={title}
                autoFocus={submitting.focusPanel}
                onAutoFocused={submitting.panelFocused}
            />
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
                    // A refused submit stays submittable (the user fixed the form); a read-only
                    // copy (another tab edits it) is submitted from that tab.
                    disabled:
                        busy ||
                        uploading ||
                        waitsForDecision(editor) ||
                        editor.saveState === 'locked',
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
