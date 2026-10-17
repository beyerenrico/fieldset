import { FormRenderer } from '@form-schema-app/form-schema-react';
import { getRouteApi, useNavigate } from '@tanstack/react-router';
import { useState, type ReactNode } from 'react';
import { ConflictPanel } from '../components/ConflictPanel.tsx';
import { ReviewNote } from '../components/ReviewNote.tsx';
import { SaveIndicator } from '../components/SaveIndicator.tsx';
import { UploadsHint } from '../components/UploadsHint.tsx';
import { Alert } from '../components/ui/alert.tsx';
import { useDraftEditor } from '../hooks/useDraftEditor.ts';
import { useI18n } from '../i18n/I18nProvider.tsx';
import type { FormVersion } from '../lib/api/types.ts';
import type { Scope } from '../lib/db/schema.ts';
import type { DraftStart } from '../lib/drafts/local-drafts.ts';
import { DraftLoader } from './DraftLoader.tsx';
import { BlockedPanel } from './review/BlockedPanel.tsx';
import { QueuedSubmitNote } from './review/QueuedSubmitNote.tsx';

const route = getRouteApi('/_auth/fill/$uuid');

export interface DraftFormProps {
    readonly uuid: string;
    readonly scope: Scope;
    readonly start: DraftStart;
    readonly version: FormVersion;
}

export function FillForm({ uuid, scope, start, version }: DraftFormProps): ReactNode {
    const { t, language } = useI18n();
    const navigate = useNavigate();
    const editor = useDraftEditor(uuid, scope, start, version);
    const { saveState } = editor;
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    const uploading = editor.pendingWork > 0;
    const blocked = editor.blockedBy !== null;

    // Review needs the draft on this device only (offline included).
    const review = async (): Promise<void> => {
        setBusy(true);
        setProblem(null);
        await editor.whenIdle();
        const result = await editor.controller.flush({ ensureCreated: true });
        setBusy(false);
        if (result.state === 'error') {
            setProblem(t('review.saveFirst'));
            return;
        }
        if (editor.conflict !== null || blocked) {
            return;
        }
        await navigate({ to: '/fill/$uuid/review', params: { uuid } });
    };

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h1 className="text-2xl font-bold">{version.definition.title}</h1>
                <SaveIndicator state={saveState} sync={editor.sync} />
            </div>
            {editor.server?.status === 'changes_requested' ? (
                <ReviewNote review={editor.server.review} />
            ) : null}
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
                    {problem}
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
                readOnly={saveState === 'locked'}
                showErrors={editor.serverErrors.length > 0}
                onAsyncWork={editor.onAsyncWork}
                primaryAction={{
                    label: t('fill.review'),
                    onClick: () => void review(),
                    busy,
                    disabled: busy || uploading || blocked,
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
            {(start, version, scope) => (
                <FillForm key={uuid} uuid={uuid} scope={scope} start={start} version={version} />
            )}
        </DraftLoader>
    );
}
