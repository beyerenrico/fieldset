/** API attachment adapter bound to one submission: previews, merging, cleanup. */
import type { AttachmentAdapter } from '@form-schema-app/form-schema-react';
import { useEffect, useMemo, useSyncExternalStore } from 'react';
import type { AttachmentRecord } from '../lib/api/types.ts';
import { createApiAttachmentAdapter, type UploadHandlers } from '../lib/attachments/api-adapter.ts';

export interface UseApiAttachmentsOptions extends UploadHandlers {
    readonly submissionUuid: string;
    readonly records: readonly AttachmentRecord[];
}

/** The adapter handed to the renderer; `version` changes when a preview URL appears. */
export interface VersionedAttachmentAdapter extends AttachmentAdapter {
    readonly version: number;
}

/**
 * One adapter per submission uuid (object URLs survive autosaves and re-renders). The returned
 * object changes identity when previews arrive, so the renderer re-renders and `get(id)` yields
 * the blob URL. Object URLs are revoked on unmount.
 */
export function useApiAttachments(options: UseApiAttachmentsOptions): VersionedAttachmentAdapter {
    const { submissionUuid, records, ensureSaved, describeError } = options;
    const adapter = useMemo(
        () => createApiAttachmentAdapter({ submissionUuid, initial: [], ensureSaved }),
        // The handlers are refreshed below; a new adapter only for a new submission.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [submissionUuid],
    );
    useEffect(() => {
        adapter.setHandlers(
            describeError === undefined ? { ensureSaved } : { ensureSaved, describeError },
        );
    }, [adapter, ensureSaved, describeError]);
    useEffect(
        () => () => {
            adapter.dispose();
        },
        [adapter],
    );
    useEffect(() => {
        adapter.merge(records);
        void adapter.loadPreviews();
    }, [adapter, records]);
    const version = useSyncExternalStore(adapter.subscribe, adapter.getVersion);
    return useMemo(
        () => ({
            version,
            upload: (request) => adapter.upload(request),
            get: (id) => adapter.get(id),
        }),
        [adapter, version],
    );
}
