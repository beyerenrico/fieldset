/**
 * Attachment adapter for the renderer, local-first (ADR 0035): `upload` stores the file in the
 * offline store and queues it; the sync engine uploads it after the draft exists on the server.
 * Photos arrive already compressed by the renderer (long edge ≤ 2048 px, re-encoded, so EXIF and
 * GPS are gone). Thumbnails are object URLs from the local bytes, or from an API download once the
 * bytes were pruned or the attachment came from the server.
 */
import type {
    AttachmentAdapter,
    AttachmentInfo,
    AttachmentUpload,
} from '@fieldset/fieldset-react';
import { downloadAttachment } from '../api/endpoints.ts';
import type { AttachmentRecord } from '../api/types.ts';
import type { AttachmentRow } from '../db/schema.ts';
import { currentScope } from '../db/scope.ts';
import { newId } from '../ids.ts';
import {
    listLocalAttachments,
    localBlob,
    removeLocalAttachment,
    storeAttachment,
} from './local-store.ts';
import { PreviewUrls } from './previews.ts';

/** The file could not be kept (`storage_full`, `not_signed_in`). */
export class AttachmentUploadError extends Error {
    override readonly name = 'AttachmentUploadError';
    readonly code: string;

    constructor(code: string, message: string) {
        super(message);
        this.code = code;
    }
}

export interface UploadHandlers {
    /**
     * Kept for the draft session's signature. Uploads no longer wait for the server draft: the
     * outbox sends the draft before its attachments.
     */
    readonly ensureSaved: () => Promise<void>;
    /** Localized text for an error code. */
    readonly describeError?: (code: string) => string;
}

export interface ApiAttachmentAdapterOptions extends UploadHandlers {
    readonly submissionUuid: string;
    readonly initial: readonly AttachmentRecord[];
    /** Parallel preview downloads (default 3). */
    readonly concurrency?: number;
    /** Registers every upload while it runs (saves and submits wait for them). */
    readonly track?: <T>(work: Promise<T>) => Promise<T>;
}

export interface ApiAttachmentAdapter extends AttachmentAdapter {
    /** Known attachment; `url` is a blob URL once its preview exists. */
    get(id: string): AttachmentInfo | undefined;
    /** Adds server records not known yet (e.g. after an autosave). */
    merge(records: readonly AttachmentRecord[]): void;
    /** Replaces the callbacks (they may close over changing state). */
    setHandlers(handlers: UploadHandlers): void;
    /** Adds local attachments and loads image previews not loaded yet (failures ignored per file). */
    loadPreviews(): Promise<void>;
    /** Notified whenever a preview URL appears. */
    readonly subscribe: (listener: () => void) => () => void;
    /** Increases with every change listeners are told about. */
    readonly getVersion: () => number;
    /** Revokes every object URL the adapter created (a later `loadPreviews()` recreates them). */
    dispose(): void;
}

function fromRecord(record: AttachmentRecord): AttachmentInfo {
    return { id: record.uuid, mime: record.mime, size: record.size };
}

function fromRow(row: AttachmentRow): AttachmentInfo {
    return { id: row.uuid, mime: row.mime, size: row.size, fileName: row.fileName };
}

function isQuotaError(error: unknown): boolean {
    const inner = (error as { inner?: unknown } | null)?.inner;
    return [error, inner].some(
        (candidate) => (candidate as Error | null)?.name === 'QuotaExceededError',
    );
}

function uploadError(
    code: string,
    fallback: string,
    handlers: UploadHandlers,
): AttachmentUploadError {
    return new AttachmentUploadError(code, handlers.describeError?.(code) ?? fallback);
}

class LocalFirstAttachmentAdapter implements ApiAttachmentAdapter {
    private readonly submissionUuid: string;
    private readonly infos = new Map<string, AttachmentInfo>();
    private readonly previews: PreviewUrls;
    private readonly track: <T>(work: Promise<T>) => Promise<T>;
    private handlers: UploadHandlers;

    constructor(options: ApiAttachmentAdapterOptions) {
        const { submissionUuid } = options;
        this.submissionUuid = submissionUuid;
        this.handlers = options;
        this.track = options.track ?? (<T>(work: Promise<T>): Promise<T> => work);
        this.previews = new PreviewUrls(
            async (id) => (await localBlob(id)) ?? downloadAttachment(submissionUuid, id),
            options.concurrency ?? 3,
        );
        this.add(options.initial.map(fromRecord));
    }

    readonly subscribe = (listener: () => void): (() => void) => this.previews.subscribe(listener);

    readonly getVersion = (): number => this.previews.version;

    upload(request: AttachmentUpload): Promise<AttachmentInfo> {
        return this.track(this.store(request));
    }

    async remove(id: string): Promise<void> {
        this.previews.drop(id);
        this.infos.delete(id);
        const scope = currentScope();
        if (scope !== null) {
            await removeLocalAttachment(scope, id);
        }
    }

    get(id: string): AttachmentInfo | undefined {
        const info = this.infos.get(id);
        const url = this.previews.url(id);
        return info === undefined || url === undefined ? info : { ...info, url };
    }

    merge(records: readonly AttachmentRecord[]): void {
        this.add(records.map(fromRecord));
    }

    setHandlers(handlers: UploadHandlers): void {
        this.handlers = handlers;
    }

    async loadPreviews(): Promise<void> {
        const scope = currentScope();
        if (scope !== null) {
            this.add((await listLocalAttachments(scope, this.submissionUuid)).map(fromRow));
        }
        const images = [...this.infos.values()].filter((info) => info.mime?.startsWith('image/'));
        await this.previews.load(images.map((info) => info.id));
    }

    dispose(): void {
        this.previews.dispose();
    }

    private add(items: readonly AttachmentInfo[]): void {
        for (const info of items) {
            if (!this.infos.has(info.id)) {
                this.infos.set(info.id, info);
            }
        }
    }

    private async store(request: AttachmentUpload): Promise<AttachmentInfo> {
        const scope = currentScope();
        if (scope === null) {
            throw uploadError('not_signed_in', 'Not signed in.', this.handlers);
        }
        const id = newId();
        let row: AttachmentRow;
        try {
            row = await storeAttachment(scope, this.submissionUuid, id, request);
        } catch (error) {
            throw isQuotaError(error)
                ? uploadError('storage_full', 'Not enough storage on this device.', this.handlers)
                : error;
        }
        const info = fromRow(row);
        this.infos.set(id, info);
        const url = this.previews.set(id, request.file);
        return url === undefined ? info : { ...info, url };
    }
}

export function createApiAttachmentAdapter(
    options: ApiAttachmentAdapterOptions,
): ApiAttachmentAdapter {
    return new LocalFirstAttachmentAdapter(options);
}
