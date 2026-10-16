/**
 * Attachment adapter for the renderer: uploads through the API after the draft exists and serves
 * thumbnails as object URLs. `<img src>` cannot send `X-Organization-Id`, so server URLs are never
 * handed to the renderer: fresh uploads use the local (compressed) blob, existing images are
 * downloaded through the API layer (`loadPreviews`).
 */
import type {
    AttachmentAdapter,
    AttachmentInfo,
    AttachmentUpload,
} from '@form-schema-app/form-schema-react';
import { downloadAttachment, uploadAttachment } from '../api/endpoints.ts';
import { ApiError } from '../api/errors.ts';
import type { AttachmentRecord } from '../api/types.ts';
import { newId } from '../ids.ts';

/** Upload rejected by the server (`file_too_large`, `file_type_not_allowed`, …). */
export class AttachmentUploadError extends Error {
    override readonly name = 'AttachmentUploadError';
    readonly code: string;

    constructor(code: string, message: string) {
        super(message);
        this.code = code;
    }
}

export interface UploadHandlers {
    /** Must guarantee the draft exists on the server (forces the first PUT). */
    readonly ensureSaved: () => Promise<void>;
    /** Localized text for an upload error code. */
    readonly describeError?: (code: string) => string;
}

export interface ApiAttachmentAdapterOptions extends UploadHandlers {
    readonly submissionUuid: string;
    readonly initial: readonly AttachmentRecord[];
    /** Parallel preview downloads (default 3). */
    readonly concurrency?: number;
}

export interface ApiAttachmentAdapter extends AttachmentAdapter {
    /** Known attachment; `url` is a blob URL once its preview exists. */
    get(id: string): AttachmentInfo | undefined;
    /** Adds server records not known yet (e.g. after an autosave). */
    merge(records: readonly AttachmentRecord[]): void;
    /** Replaces the upload callbacks (they may close over changing state). */
    setHandlers(handlers: UploadHandlers): void;
    /** Downloads image previews not loaded yet; failures are ignored per file. */
    loadPreviews(): Promise<void>;
    /** Notified whenever a preview URL appears. */
    readonly subscribe: (listener: () => void) => () => void;
    /** Increases with every change listeners are told about. */
    readonly getVersion: () => number;
    /** Revokes every object URL the adapter created (a later `loadPreviews()` recreates them). */
    dispose(): void;
}

function createUrl(blob: Blob): string | undefined {
    return typeof URL.createObjectURL === 'function' ? URL.createObjectURL(blob) : undefined;
}

function revokeUrl(url: string): void {
    if (typeof URL.revokeObjectURL === 'function') {
        URL.revokeObjectURL(url);
    }
}

function fromRecord(record: AttachmentRecord): AttachmentInfo {
    return { id: record.uuid, mime: record.mime, size: record.size };
}

export function createApiAttachmentAdapter(
    options: ApiAttachmentAdapterOptions,
): ApiAttachmentAdapter {
    const infos = new Map<string, AttachmentInfo>();
    const urls = new Map<string, string>();
    /** Preview downloads in flight → the generation that started them. */
    const loading = new Map<string, number>();
    const listeners = new Set<() => void>();
    let version = 0;
    let generation = 0;
    let handlers: UploadHandlers = options;

    const notify = (): void => {
        version++;
        for (const listener of listeners) {
            listener();
        }
    };
    const setUrl = (id: string, blob: Blob): string | undefined => {
        const url = createUrl(blob);
        if (url !== undefined) {
            urls.set(id, url);
        }
        return url;
    };
    const merge = (records: readonly AttachmentRecord[]): void => {
        for (const record of records) {
            if (!infos.has(record.uuid)) {
                infos.set(record.uuid, fromRecord(record));
            }
        }
    };
    const preview = async (id: string, run: number): Promise<void> => {
        loading.set(id, run);
        try {
            const blob = await downloadAttachment(options.submissionUuid, id);
            if (run === generation && !urls.has(id) && setUrl(id, blob) !== undefined) {
                notify();
            }
        } catch {
            // A missing preview only hides the thumbnail; the attachment itself is unaffected.
        } finally {
            if (loading.get(id) === run) {
                loading.delete(id);
            }
        }
    };

    merge(options.initial);

    return {
        async upload(request: AttachmentUpload): Promise<AttachmentInfo> {
            await handlers.ensureSaved();
            let record: AttachmentRecord;
            try {
                record = await uploadAttachment(options.submissionUuid, {
                    file: request.file,
                    fileName: request.fileName,
                    attachmentUuid: newId(),
                    kind: request.kind,
                    elementKey: request.elementKey,
                });
            } catch (error) {
                if (error instanceof ApiError && error.status === 422) {
                    const text = handlers.describeError?.(error.code) ?? error.message;
                    throw new AttachmentUploadError(error.code, text);
                }
                throw error;
            }
            const info: AttachmentInfo = { ...fromRecord(record), fileName: request.fileName };
            infos.set(info.id, info);
            const url = setUrl(info.id, request.file);
            return url === undefined ? info : { ...info, url };
        },
        get(id: string): AttachmentInfo | undefined {
            const info = infos.get(id);
            const url = urls.get(id);
            return info === undefined || url === undefined ? info : { ...info, url };
        },
        merge,
        setHandlers(next: UploadHandlers): void {
            handlers = next;
        },
        async loadPreviews(): Promise<void> {
            const run = generation;
            const queue = [...infos.values()]
                .filter((info) => info.mime?.startsWith('image/') === true)
                .map((info) => info.id)
                .filter((id) => !urls.has(id) && loading.get(id) !== run);
            const worker = async (): Promise<void> => {
                for (let id = queue.shift(); id !== undefined; id = queue.shift()) {
                    await preview(id, run);
                }
            };
            const lanes = Math.max(1, options.concurrency ?? 3);
            await Promise.all(Array.from({ length: Math.min(lanes, queue.length) }, worker));
        },
        subscribe: (listener: () => void): (() => void) => {
            listeners.add(listener);
            return () => {
                listeners.delete(listener);
            };
        },
        getVersion: () => version,
        dispose(): void {
            generation++;
            for (const url of urls.values()) {
                revokeUrl(url);
            }
            urls.clear();
            notify();
        },
    };
}
