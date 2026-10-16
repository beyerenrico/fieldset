import { FormRenderer } from '@form-schema-app/form-schema-react';
import { getRouteApi, Link, useNavigate } from '@tanstack/react-router';
import { useState, type ReactNode } from 'react';
import { ConflictPanel } from '../components/ConflictPanel.tsx';
import { SaveIndicator } from '../components/SaveIndicator.tsx';
import { Alert } from '../components/ui/alert.tsx';
import { Button } from '../components/ui/button.tsx';
import { useDraftEditor } from '../hooks/useDraftEditor.ts';
import { useI18n } from '../i18n/I18nProvider.tsx';
import type { FormVersion, Submission } from '../lib/api/types.ts';
import { DraftLoader } from './DraftLoader.tsx';

const route = getRouteApi('/_auth/fill/$uuid');

export function FillForm({
    uuid,
    submission,
    version,
}: {
    readonly uuid: string;
    readonly submission: Submission | null;
    readonly version: FormVersion;
}): ReactNode {
    const { t, language } = useI18n();
    const navigate = useNavigate();
    const editor = useDraftEditor(uuid, submission, version);
    const { snapshot } = editor;
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    const review = async (): Promise<void> => {
        setBusy(true);
        setProblem(null);
        const result = await editor.controller.flush({ ensureCreated: true });
        setBusy(false);
        if (result.state === 'offline' || result.state === 'error' || result.revision === 0) {
            setProblem(t('review.saveFirst'));
            return;
        }
        if (result.state === 'conflict' || result.state === 'locked') {
            return;
        }
        await navigate({ to: '/fill/$uuid/review', params: { uuid } });
    };

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h1 className="text-2xl font-bold">{version.definition.title}</h1>
                <SaveIndicator state={snapshot.state} />
            </div>
            {snapshot.state === 'locked' ? (
                <Alert role="alert">
                    <p>{t('fill.locked')}</p>
                    <Button asChild variant="outline" className="mt-2">
                        <Link to="/submissions/$uuid" params={{ uuid }}>
                            {t('fill.openDetail')}
                        </Link>
                    </Button>
                </Alert>
            ) : null}
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
                    {problem}
                </Alert>
            )}
            <FormRenderer
                definition={version.definition}
                data={editor.data}
                context={editor.context}
                onChange={editor.onChange}
                errors={editor.serverErrors}
                attachments={editor.attachments}
                uiLocale={language}
                readOnly={snapshot.state === 'locked'}
                showErrors={editor.serverErrors.length > 0}
                primaryAction={{
                    label: t('fill.review'),
                    onClick: () => void review(),
                    busy,
                    disabled: busy || snapshot.state === 'locked',
                }}
            />
        </div>
    );
}

export function FillScreen(): ReactNode {
    const { uuid } = route.useParams();
    const { formId, versionId } = route.useSearch();
    return (
        <DraftLoader uuid={uuid} formId={formId} versionId={versionId}>
            {(submission, version) => (
                <FillForm key={uuid} uuid={uuid} submission={submission} version={version} />
            )}
        </DraftLoader>
    );
}
