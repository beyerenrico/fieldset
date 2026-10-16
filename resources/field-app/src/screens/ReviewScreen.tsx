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
import type { FormVersion, Submission } from '../lib/api/types.ts';
import { DraftLoader } from './DraftLoader.tsx';
import { useSubmit } from './review/useSubmit.ts';

const route = getRouteApi('/_auth/fill/$uuid/review');

function ReviewForm({
    uuid,
    submission,
    version,
}: {
    readonly uuid: string;
    readonly submission: Submission | null;
    readonly version: FormVersion;
}): ReactNode {
    const { t, language } = useI18n();
    const editor = useDraftEditor(uuid, submission, version);
    const { busy, problem, showErrors, submit } = useSubmit(uuid, version, editor);
    const { snapshot } = editor;
    const uploading = editor.pendingWork > 0;

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <p className="text-muted-foreground">{version.definition.title}</p>
                    <h1 className="text-2xl font-bold">{t('review.title')}</h1>
                </div>
                <SaveIndicator state={snapshot.state} />
            </div>
            <Button asChild variant="outline" className="self-start">
                <Link to="/fill/$uuid" params={{ uuid }}>
                    {t('review.backToForm')}
                </Link>
            </Button>
            {snapshot.conflict === null ? null : (
                <ConflictPanel
                    definition={version.definition}
                    mine={editor.data}
                    server={snapshot.conflict.data}
                    onChoose={editor.resolveConflict}
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
                showErrors={showErrors}
                onAsyncWork={editor.onAsyncWork}
                primaryAction={{
                    label: busy ? t('review.submitting') : t('review.submit'),
                    onClick: () => void submit(),
                    busy,
                    disabled:
                        busy ||
                        uploading ||
                        snapshot.state === 'conflict' ||
                        snapshot.state === 'locked',
                }}
            />
        </div>
    );
}

export function ReviewScreen(): ReactNode {
    const { uuid } = route.useParams();
    return (
        <DraftLoader uuid={uuid}>
            {(submission, version) => (
                <ReviewForm key={uuid} uuid={uuid} submission={submission} version={version} />
            )}
        </DraftLoader>
    );
}
