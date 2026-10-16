/** Shared state of the fill and review screens: data, autosave, context, attachments, uploads. */
import type { EvaluationContext, JsonObject } from '@form-schema-app/form-schema-js';
import type { AttachmentAdapter } from '@form-schema-app/form-schema-react';
import { useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useMemo, useSyncExternalStore } from 'react';
import { useT, type Translate } from '../i18n/I18nProvider.tsx';
import type { ValidationDetail } from '../lib/api/errors.ts';
import type { AttachmentRecord, FormVersion, Submission } from '../lib/api/types.ts';
import type { AutosaveController } from '../lib/autosave/controller.ts';
import type { AutosaveSnapshot } from '../lib/autosave/types.ts';
import {
    openDraftSession,
    retainDraftSession,
    type DraftSession,
    type DraftSessionInit,
} from '../lib/drafts/draft-session.ts';
import { reportAuthError } from '../lib/session/auth-events.ts';
import { buildEvaluationContext } from '../lib/session/eval-context.ts';
import {
    getSubmitErrors,
    setSubmitErrors,
    subscribeSubmitErrors,
} from '../lib/submit/error-store.ts';
import { submissionQueryOptions } from './queries.ts';
import { useApiAttachments } from './useApiAttachments.ts';
import { useSession } from './useSession.ts';

export interface DraftEditor {
    readonly data: JsonObject;
    readonly onChange: (data: JsonObject) => void;
    readonly controller: AutosaveController;
    readonly snapshot: AutosaveSnapshot;
    readonly context: EvaluationContext;
    readonly attachments: AttachmentAdapter;
    readonly serverErrors: readonly ValidationDetail[];
    readonly resolveConflict: (choice: 'mine' | 'server') => void;
    /** Uploads, signatures and location captures still running (also from a left screen). */
    readonly pendingWork: number;
    /** Hand to the renderer's `onAsyncWork`. */
    readonly onAsyncWork: (work: Promise<void>) => void;
    /** Resolves once every pending edit has been written. */
    readonly whenIdle: () => Promise<void>;
}

const NO_RECORDS: readonly AttachmentRecord[] = [];
const UPLOAD_CODES = new Set(['file_too_large', 'file_type_not_allowed']);

export function uploadErrorText(t: Translate, code: string): string {
    return UPLOAD_CODES.has(code)
        ? t(code === 'file_too_large' ? 'upload.file_too_large' : 'upload.file_type_not_allowed')
        : t('upload.failed');
}

/** The draft's session (shared with other screens), retained while mounted. */
export function useDraftSession(init: DraftSessionInit): DraftSession {
    const session = openDraftSession(init);
    useEffect(() => retainDraftSession(session), [session]);
    return session;
}

export function useDraftEditor(
    uuid: string,
    submission: Submission | null,
    version: FormVersion,
): DraftEditor {
    const t = useT();
    const queryClient = useQueryClient();
    const { me, organization } = useSession();
    const session = useDraftSession({
        uuid,
        formVersionId: version.id,
        submission,
        // Also after the screens are gone: reopening the draft starts from the saved copy.
        onSaved: (saved) => {
            queryClient.setQueryData(submissionQueryOptions(uuid).queryKey, saved);
        },
        onAuthLost: reportAuthError,
    });
    const { controller, work } = session;
    const snapshot = useSyncExternalStore(controller.subscribe, controller.getSnapshot);
    const data = useSyncExternalStore(controller.subscribe, () => controller.data);
    const pendingWork = useSyncExternalStore(work.subscribe, work.getPending);
    const serverErrors = useSyncExternalStore(subscribeSubmitErrors, () => getSubmitErrors(uuid));
    const context = useMemo(
        () => buildEvaluationContext(me, organization, version.version),
        [me, organization, version.version],
    );

    const attachments = useApiAttachments({
        adapter: session.attachments,
        submissionUuid: uuid,
        records: snapshot.submission?.attachments ?? submission?.attachments ?? NO_RECORDS,
        ensureSaved: async () => {
            const result = await controller.flush({ ensureCreated: true });
            if (result.revision === 0) {
                throw new Error(t('review.saveFirst'));
            }
        },
        describeError: (code) => uploadErrorText(t, code),
    });

    const onChange = useCallback(
        (next: JsonObject) => {
            controller.update(next);
            setSubmitErrors(uuid, []);
        },
        [controller, uuid],
    );
    const resolveConflict = useCallback(
        (choice: 'mine' | 'server') => {
            controller.resolveConflict(choice);
        },
        [controller],
    );
    const onAsyncWork = useCallback(
        (pending: Promise<void>) => {
            void work.track(pending);
        },
        [work],
    );

    return {
        data,
        onChange,
        controller,
        snapshot,
        context,
        attachments,
        serverErrors,
        resolveConflict,
        pendingWork,
        onAsyncWork,
        whenIdle: work.idle,
    };
}
