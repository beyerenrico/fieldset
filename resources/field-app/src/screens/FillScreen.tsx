import { FormRenderer } from '@form-schema-app/form-schema-react';
import { getRouteApi, useNavigate } from '@tanstack/react-router';
import { useRef, useState, type ReactNode } from 'react';
import { ReviewNote } from '../components/ReviewNote.tsx';
import { isStorageFull, SaveIndicator } from '../components/SaveIndicator.tsx';
import { UploadsHint } from '../components/UploadsHint.tsx';
import { Alert } from '../components/ui/alert.tsx';
import { useDraftEditor } from '../hooks/useDraftEditor.ts';
import { useI18n } from '../i18n/I18nProvider.tsx';
import type { MessageKey } from '../i18n/en.ts';
import type { FormVersion } from '../lib/api/types.ts';
import type { Scope } from '../lib/db/schema.ts';
import type { DraftStart } from '../lib/drafts/local-drafts.ts';
import { DraftLoader } from './DraftLoader.tsx';
import { FlashNotice } from './review/flash.tsx';
import { lockedElsewhere, SyncPanels, waitsForDecision } from './review/SyncPanels.tsx';

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
    const title = useRef<HTMLHeadingElement>(null);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<MessageKey | null>(null);

    const uploading = editor.pendingWork > 0;
    // A conflict or a blocking answer is decided first (its panel says how); a refused submit is
    // fixed in the form and reviewed again.
    const paused = waitsForDecision(editor);

    // Review needs the draft on this device only (offline included).
    const review = async (): Promise<void> => {
        setBusy(true);
        setProblem(null);
        try {
            await editor.whenIdle();
            const result = await editor.controller.flush({ ensureCreated: true });
            if (result.state === 'error') {
                setProblem(isStorageFull(result.error) ? 'save.storageFull' : 'review.saveFirst');
                return;
            }
            if (!paused) {
                await navigate({ to: '/fill/$uuid/review', params: { uuid } });
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="flex flex-col gap-3">
            <FlashNotice />
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h1 ref={title} tabIndex={-1} className="text-2xl font-bold outline-none">
                    {version.definition.title}
                </h1>
                <SaveIndicator
                    state={saveState}
                    sync={editor.sync}
                    error={editor.snapshot.error}
                    lockedElsewhere={lockedElsewhere(editor)}
                />
            </div>
            {editor.server?.status === 'changes_requested' ? (
                <ReviewNote review={editor.server.review} />
            ) : null}
            <SyncPanels editor={editor} version={version} formId={start.formId} titleRef={title} />
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
                readOnly={saveState === 'locked'}
                showErrors={editor.serverErrors.length > 0}
                onAsyncWork={editor.onAsyncWork}
                primaryAction={{
                    label: t('fill.review'),
                    onClick: () => void review(),
                    busy,
                    // Also while a conflict is open: Review would do nothing (the panel decides).
                    disabled: busy || uploading || paused,
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
