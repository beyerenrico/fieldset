/**
 * Shared state of the fill and review screens: data, local autosave, the draft's sync state from
 * the offline store (conflict, blocked, submit errors), context, attachments, uploads.
 */
import type { EvaluationContext, JsonObject } from '@form-schema-app/form-schema-js';
import type { AttachmentAdapter } from '@form-schema-app/form-schema-react';
import { useCallback, useEffect, useMemo, useSyncExternalStore } from 'react';
import { useT, type Translate } from '../i18n/I18nProvider.tsx';
import type { ValidationDetail } from '../lib/api/errors.ts';
import type { AttachmentRecord, FormVersion, Submission } from '../lib/api/types.ts';
import type { AutosaveController } from '../lib/autosave/controller.ts';
import type { AutosaveSnapshot, SaveState } from '../lib/autosave/types.ts';
import type { DraftRow, DraftSync, OutboxRow, Scope } from '../lib/db/schema.ts';
import {
    openDraftSession,
    retainDraftSession,
    type DraftSession,
    type DraftSessionInit,
} from '../lib/drafts/draft-session.ts';
import type { DraftStart } from '../lib/drafts/local-drafts.ts';
import { buildEvaluationContext } from '../lib/session/eval-context.ts';
import { resolveConflict as resolveStoredConflict } from '../lib/sync/engine.ts';
import { useApiAttachments } from './useApiAttachments.ts';
import { useLocalSubmission } from './useLocalDrafts.ts';
import { useSession } from './useSession.ts';

export interface DraftEditor {
    readonly uuid: string;
    readonly scope: Scope;
    readonly data: JsonObject;
    readonly onChange: (data: JsonObject) => void;
    readonly controller: AutosaveController;
    readonly snapshot: AutosaveSnapshot;
    /** What the save indicator shows: the local state, or the row's conflict / lock. */
    readonly saveState: SaveState;
    /** Sync state of the draft (null before the first local write). */
    readonly sync: DraftSync | null;
    readonly row: DraftRow | null;
    readonly ops: readonly OutboxRow[];
    /** Server copy of a revision conflict, until the user decides. */
    readonly conflict: Submission | null;
    /** Error code that blocks the draft (not_editable, version_retired, …). */
    readonly blockedBy: string | null;
    /** A submit waits in the outbox ("number pending"). */
    readonly queuedSubmit: boolean;
    /** Latest server copy known (status, number, review). */
    readonly server: Submission | null;
    readonly context: EvaluationContext;
    readonly attachments: AttachmentAdapter;
    readonly serverErrors: readonly ValidationDetail[];
    readonly resolveConflict: (choice: 'mine' | 'server') => Promise<void>;
    /** Uploads, signatures and location captures still running (also from a left screen). */
    readonly pendingWork: number;
    /** Hand to the renderer's `onAsyncWork`. */
    readonly onAsyncWork: (work: Promise<void>) => void;
    /** Resolves once every pending edit has been written. */
    readonly whenIdle: () => Promise<void>;
}

const NO_RECORDS: readonly AttachmentRecord[] = [];
const NO_ERRORS: readonly ValidationDetail[] = [];
const NO_OPS: readonly OutboxRow[] = [];
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

function displayState(state: SaveState, row: DraftRow | null): SaveState {
    if (row?.sync === 'conflict') {
        return 'conflict';
    }
    return row?.sync === 'blocked' && row.blockedBy === 'not_editable' ? 'locked' : state;
}

export function useDraftEditor(
    uuid: string,
    scope: Scope,
    start: DraftStart,
    version: FormVersion,
): DraftEditor {
    const t = useT();
    const { me, organization } = useSession();
    const session = useDraftSession({
        uuid,
        scope,
        formId: start.formId,
        formVersionId: start.formVersionId,
        submission: start.submission,
        data: start.data,
        localVersion: start.local?.localVersion ?? 0,
    });
    const { controller, work } = session;
    const snapshot = useSyncExternalStore(controller.subscribe, controller.getSnapshot);
    const data = useSyncExternalStore(controller.subscribe, () => controller.data);
    const pendingWork = useSyncExternalStore(work.subscribe, work.getPending);
    const local = useLocalSubmission(uuid, scope);
    const row = local?.row ?? start.local;
    const ops = local?.ops ?? NO_OPS;
    const server = row?.server ?? start.submission;
    const context = useMemo(
        () => buildEvaluationContext(me, organization, version.version),
        [me, organization, version.version],
    );

    const shared = useApiAttachments({
        adapter: session.attachments,
        submissionUuid: uuid,
        records: server?.attachments ?? NO_RECORDS,
        ensureSaved: async () => {
            if ((await controller.flush({ ensureCreated: true })).state === 'error') {
                throw new Error(t('review.saveFirst'));
            }
        },
        describeError: (code) => uploadErrorText(t, code),
    });
    // The draft row must exist before an attachment is queued: the outbox creates the server draft
    // from it before any upload (a new draft may get its photo before its first edit).
    const attachments = useMemo<AttachmentAdapter>(
        () => ({
            ...shared,
            upload: async (request) => {
                if ((await controller.flush({ ensureCreated: true })).state === 'error') {
                    throw new Error(t('review.saveFirst'));
                }
                return shared.upload(request);
            },
        }),
        [shared, controller, t],
    );

    const onChange = useCallback(
        (next: JsonObject) => {
            controller.update(next);
        },
        [controller],
    );
    const conflict = row?.sync === 'conflict' ? row.conflict : null;
    const resolveConflict = useCallback(
        async (choice: 'mine' | 'server') => {
            if (choice === 'server' && conflict !== null) {
                await controller.replaceData(conflict.data);
            } else {
                await controller.flush();
            }
            await resolveStoredConflict(uuid, choice);
        },
        [controller, conflict, uuid],
    );
    const onAsyncWork = useCallback(
        (pending: Promise<void>) => {
            void work.track(pending);
        },
        [work],
    );

    return {
        uuid,
        scope,
        data,
        onChange,
        controller,
        snapshot,
        saveState: displayState(snapshot.state, row),
        sync: row?.sync ?? null,
        row,
        ops,
        conflict,
        blockedBy: row?.sync === 'blocked' ? row.blockedBy : null,
        queuedSubmit: ops.some((op) => op.kind === 'submit'),
        server,
        context,
        attachments,
        serverErrors: row?.submitErrors ?? NO_ERRORS,
        resolveConflict,
        pendingWork,
        onAsyncWork,
        whenIdle: work.idle,
    };
}
