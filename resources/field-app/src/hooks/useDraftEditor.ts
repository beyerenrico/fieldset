/** Shared state of the fill and review screens: data, autosave, context, attachments. */
import type { EvaluationContext, JsonObject } from '@form-schema-app/form-schema-js';
import type { AttachmentAdapter } from '@form-schema-app/form-schema-react';
import { useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useMemo, useState, useSyncExternalStore } from 'react';
import { useT, type Translate } from '../i18n/I18nProvider.tsx';
import type { ValidationDetail } from '../lib/api/errors.ts';
import type { AttachmentRecord, FormVersion, Submission } from '../lib/api/types.ts';
import type { AutosaveController } from '../lib/autosave/controller.ts';
import type { AutosaveSnapshot } from '../lib/autosave/types.ts';
import { useAutosave } from '../lib/autosave/useAutosave.ts';
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
}

const NO_RECORDS: readonly AttachmentRecord[] = [];
const UPLOAD_CODES = new Set(['file_too_large', 'file_type_not_allowed']);

export function uploadErrorText(t: Translate, code: string): string {
    return UPLOAD_CODES.has(code)
        ? t(code === 'file_too_large' ? 'upload.file_too_large' : 'upload.file_type_not_allowed')
        : t('upload.failed');
}

export function useDraftEditor(
    uuid: string,
    submission: Submission | null,
    version: FormVersion,
): DraftEditor {
    const t = useT();
    const queryClient = useQueryClient();
    const { me, organization } = useSession();
    const initial = submission?.data ?? {};
    const [data, setData] = useState<JsonObject>(initial);
    const { controller, snapshot } = useAutosave({
        uuid,
        formVersionId: version.id,
        revision: submission?.revision ?? 0,
        data: initial,
    });
    const serverErrors = useSyncExternalStore(subscribeSubmitErrors, () => getSubmitErrors(uuid));
    const context = useMemo(
        () => buildEvaluationContext(me, organization, version.version),
        [me, organization, version.version],
    );

    useEffect(() => {
        if (snapshot.submission !== null) {
            queryClient.setQueryData(submissionQueryOptions(uuid).queryKey, snapshot.submission);
        }
    }, [queryClient, snapshot.submission, uuid]);

    const attachments = useApiAttachments({
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
            setData(next);
            controller.update(next);
            setSubmitErrors(uuid, []);
        },
        [controller, uuid],
    );

    const resolveConflict = useCallback(
        (choice: 'mine' | 'server') => {
            setData(controller.resolveConflict(choice));
        },
        [controller],
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
    };
}
